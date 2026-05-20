<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LowAttendanceAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public $student;
    public $course;
    public $percentage;

    public function __construct($student, $course, $percentage)
    {
        $this->student = $student;
        $this->course = $course;
        $this->percentage = $percentage;
    }

    public function build()
    {
        $subject = "Low Attendance Notice: {$this->course->name}";

        $html = "<h4>Attendance Warning</h4>";
        $html .= "<p>Dear {$this->student->user->name},</p>";
        $html .= "<p>Your attendance for the course <strong>{$this->course->name}</strong> is currently <strong>{$this->percentage}%</strong>. " .
             "This is below the required threshold. Please contact your instructor or improve your attendance to avoid penalties.</p>";

        $html .= "<p>Regards,<br/>Ali Academy</p>";

        return $this->subject($subject)
                ->html($html);
        }
    }
