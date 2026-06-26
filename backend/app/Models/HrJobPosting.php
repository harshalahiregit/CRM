<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class HrJobPosting extends Model
{
    use HasFactory;

    protected $table = 'hr_job_postings';

    protected $fillable = [
        'tenant_id','title','department','location','job_type','posting_type',
        'description','requirements','salary_from','salary_to',
        'number_of_openings','closing_date','status','sources','applicant_count',
    ];

    protected $casts = [
        'closing_date' => 'date',
        'sources'      => 'array',
        'salary_from'  => 'decimal:2',
        'salary_to'    => 'decimal:2',
    ];

    public function candidates()
    {
        return $this->hasMany(HrCandidate::class, 'job_posting_id');
    }
}
