<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AttendanceSummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    public $summary;

    public function __construct(array $summary)
    {
        $this->summary = $summary;
    }

    public function build()
    {
        $subject = "Daily Attendance Summary: {$this->summary['course_name']} ({$this->summary['date']})";

        $html = "<h3>Attendance Summary</h3>";
        $html .= "<p>Course: {$this->summary['course_name']} (ID: {$this->summary['course_id']})</p>";
        $html .= "<p>Date: {$this->summary['date']}</p>";
        $html .= "<ul>";
        $html .= "<li>Present: {$this->summary['present']}</li>";
        $html .= "<li>Absent: {$this->summary['absent']}</li>";
        $html .= "</ul>";

        return $this->subject($subject)
                    ->html($html);
    }
}
