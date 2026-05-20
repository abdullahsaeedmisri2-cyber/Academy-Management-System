<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Mail\AttendanceSummaryMail;
use App\Mail\LowAttendanceAlertMail;
use App\Models\Course;
use App\Models\Attendance;
use App\Models\Grade;
use App\Models\Enrollment;

class TeacherController extends Controller
{
    /**
     * Teacher Dashboard
     * Displays only the courses assigned to this specific teacher.
     */
    public function dashboard()
    {
        $user = Auth::user();
        
        // Ensure the User has a Teacher profile
        if (!$user->teacherProfile) {
            abort(403, 'Teacher profile not found. Contact Admin.');
        }

        // Fetch courses linked to the Teacher Profile ID
        $courses = $user->teacherProfile->courses()->withCount('enrollments')->get();
        
        // Calculate Stats for Admin-like Dashboard
        $stats = [
            'courses' => $courses->count(),
            'students' => $courses->sum('enrollments_count'),
            'tasks' => 0 // Future: $courses->flatMap->tasks->count()
        ];
        
        return view('teacher.dashboard', compact('courses', 'stats'));
    }

    /**
     * Show Course Details (Classroom View)
     * Lists students for taking attendance or grading.
     */
    public function showCourse(Course $course)
    {
        // Security Check: Does this course belong to the logged-in teacher?
        if ($course->teacher_id !== Auth::user()->teacherProfile->id) {
            abort(403, 'Unauthorized access to this course.');
        }

        // Eager load relationships deeply to get student names
        // Course -> Enrollments -> Student -> User (Name/Email)
        $course->load(['enrollments.student.user', 'enrollments.attendances' => function($q) {
            $q->whereDate('date', today()); // Load today's attendance if exists
        }]);

        return view('teacher.courses.show', compact('course'));
    }

    /**
     * Store Attendance
     * Updates or Creates attendance records for the specific date.
     */
    public function storeAttendance(Request $request, Course $course)
    {
        // Security Check
        if ($course->teacher_id !== Auth::user()->teacherProfile->id) {
            abort(403);
        }

        $data = $request->validate([
            'date' => 'required|date',
            'attendances' => 'required|array',
            'attendances.*' => 'required|in:' . implode(',', [
                Attendance::STATUS_PRESENT,
                Attendance::STATUS_ABSENT,
                Attendance::STATUS_LATE
            ]),
        ]);

        // Counters for daily summary
        $presentCount = 0;
        $absentCount = 0;

        foreach ($data['attendances'] as $enrollmentId => $status) {
            $enrollment = Enrollment::find($enrollmentId);
            if (!$enrollment) {
                Log::warning("Attendance: Enrollment ID {$enrollmentId} not found.");
                continue;
            }

            $studentId = $enrollment->student_id;

            // Save attendance using student/course/date to match DB schema
            $attendance = Attendance::updateOrCreate(
                [
                    'student_id' => $studentId,
                    'course_id' => $course->id,
                    'date' => $data['date']
                ],
                [
                    'status' => $status,
                ]
            );

            // Update daily counters
            if ($status === Attendance::STATUS_PRESENT) {
                $presentCount++;
            } elseif ($status === Attendance::STATUS_ABSENT) {
                $absentCount++;
            }

            // --- Per-student overall attendance percentage for this course ---
            $totalClasses = Attendance::where('student_id', $studentId)
                ->where('course_id', $course->id)
                ->count();

            $presentClasses = Attendance::where('student_id', $studentId)
                ->where('course_id', $course->id)
                ->where('status', Attendance::STATUS_PRESENT)
                ->count();

            $attendancePercentage = $totalClasses > 0 ? round(($presentClasses / $totalClasses) * 100) : 0;

            // If attendance drops below threshold, alert the student
            if ($attendancePercentage < 75) {
                try {
                    $userEmail = $enrollment->student->user->email ?? null;
                    if ($userEmail) {
                        Mail::to($userEmail)->queue(new LowAttendanceAlertMail($enrollment->student, $course, $attendancePercentage));
                    }
                } catch (\Exception $e) {
                    Log::error('Low attendance alert failed: ' . $e->getMessage());
                }
            }

            // --- Late policy: count 'late' for current month and take action ---
            if ($status === Attendance::STATUS_LATE) {
                $startOfMonth = Carbon::parse($data['date'])->startOfMonth()->toDateString();
                $endOfMonth = Carbon::parse($data['date'])->endOfMonth()->toDateString();

                $lateCountThisMonth = Attendance::where('student_id', $studentId)
                    ->where('course_id', $course->id)
                    ->whereBetween('date', [$startOfMonth, $endOfMonth])
                    ->where('status', Attendance::STATUS_LATE)
                    ->count();

                if ($lateCountThisMonth >= 3) {
                    // Persist a warning counter on the student record (migration required)
                    try {
                        $student = $enrollment->student;
                        if ($student) {
                            $student->increment('warning_count', 1);
                        }

                        // also log and optionally notify admin
                        Log::warning("Student {$studentId} reached {$lateCountThisMonth} lates in month for course {$course->id}");
                    } catch (\Exception $e) {
                        Log::error('Failed to increment warning_count: ' . $e->getMessage());
                    }
                }
            }
        }

        // Send daily summary to admin
        try {
            $summary = [
                'course_id' => $course->id,
                'course_name' => $course->name,
                'date' => $data['date'],
                'present' => $presentCount,
                'absent' => $absentCount,
            ];

            Mail::to('admin@aliacademy.com')->queue(new AttendanceSummaryMail($summary));
        } catch (\Exception $e) {
            Log::error('Attendance summary mail failed: ' . $e->getMessage());
        }

        return back()->with('success', 'Attendance recorded successfully.');
    }

    /**
     * Store Grades
     * Records assessment scores for the class.
     */
    public function storeGrade(Request $request, Course $course)
    {
        if ($course->teacher_id !== Auth::user()->teacherProfile->id) {
            abort(403);
        }

        $data = $request->validate([
            'assessment_name' => 'required|string|max:255',
            'max_score' => 'required|numeric|min:1',
            'grades' => 'required|array',
            'grades.*' => 'required|numeric|min:0|lte:max_score', // Ensure score isn't higher than max
        ]);

        foreach ($data['grades'] as $enrollmentId => $score) {
            // Check if score is provided (ignore empty inputs)
            if ($score !== null) {
                // Logic Improvement: Use updateOrCreate to allow editing grades for the same assessment
                Grade::updateOrCreate(
                    [
                        'enrollment_id'   => $enrollmentId,
                        'assessment_name' => $data['assessment_name'],
                    ],
                    [
                        'score'     => $score,
                        'max_score' => $data['max_score'],
                        'feedback'  => $request->input("feedback.$enrollmentId"),
                    ]
                );
            }
        }

        return back()->with('success', 'Grades recorded successfully.');
    }

    // ==========================================
    // COURSE MANAGEMENT CRUD (New Feature)
    // ==========================================

    public function create()
    {
        return view('teacher.courses.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'course_code' => 'required|string|unique:courses,course_code|max:20',
            'description' => 'nullable|string',
        ]);

        Auth::user()->teacherProfile->courses()->create($validated);

        return redirect()->route('teacher.dashboard')->with('success', 'Class created successfully.');
    }

    public function edit(Course $course)
    {
        if ($course->teacher_id !== Auth::user()->teacherProfile->id) {
            abort(403);
        }
        return view('teacher.courses.edit', compact('course'));
    }

    public function update(Request $request, Course $course)
    {
        if ($course->teacher_id !== Auth::user()->teacherProfile->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'course_code' => 'required|string|max:20|unique:courses,course_code,' . $course->id,
            'description' => 'nullable|string',
        ]);

        $course->update($validated);

        return redirect()->route('teacher.dashboard')->with('success', 'Class details updated.');
    }

    public function destroy(Course $course)
    {
        if ($course->teacher_id !== Auth::user()->teacherProfile->id) {
            abort(403);
        }

        $course->delete();

        return redirect()->route('teacher.dashboard')->with('success', 'Class has been deleted.');
    }
}