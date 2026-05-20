<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Grade extends Model
{
    use HasFactory;

    protected $fillable = [
        'enrollment_id', 
        'assessment_name', // e.g., "Midterm", "FSC Mock Exam"
        'score', 
        'max_score', 
        'feedback'
    ];

    /**
     * Relationship: A grade belongs to a specific student enrollment.
     */
    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Logic Enhancements (Professional Features)
    |--------------------------------------------------------------------------
    */

    /**
     * Get the percentage for the grade.
     * Usage: $grade->percentage
     */
    public function getPercentageAttribute(): float
    {
        if ($this->max_score <= 0) return 0;
        return round(($this->score / $this->max_score) * 100, 2);
    }

    /**
     * Determine a letter grade based on the score.
     * Useful for Cadet College entry prep reporting.
     */
    public function getLetterGradeAttribute(): string
    {
        $p = $this->percentage;
        if ($p >= 80) return 'A+';
        if ($p >= 70) return 'A';
        if ($p >= 60) return 'B';
        if ($p >= 50) return 'C';
        return 'F';
    }
}