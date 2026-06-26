<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\HrManpowerRequest;
use App\Models\HrJobPosting;
use App\Models\HrCandidate;
use App\Models\HrInterviewRound;
use App\Models\HrOffer;
use App\Models\HrOnboarding;
use App\Models\HrEmployee;

class HRSeeder extends Seeder
{
    public function run(): void
    {
        // ── Job Postings ────────────────────────────────────────────────
        $jobs = [
            ['title'=>'Senior React Developer','department'=>'Engineering','location'=>'Bangalore','job_type'=>'Full-time','posting_type'=>'Both','description'=>'We are looking for an experienced React developer to join our team.','requirements'=>'5+ years React, TypeScript, Node.js','salary_from'=>800000,'salary_to'=>1400000,'number_of_openings'=>2,'closing_date'=>'2025-07-30','status'=>'Active','sources'=>['LinkedIn','Career Page','Internal Portal'],'applicant_count'=>34],
            ['title'=>'Sales Executive','department'=>'Sales','location'=>'Mumbai','job_type'=>'Full-time','posting_type'=>'External','description'=>'Drive B2B sales in western region.','requirements'=>'2+ years B2B sales experience','salary_from'=>400000,'salary_to'=>700000,'number_of_openings'=>3,'closing_date'=>'2025-07-05','status'=>'Active','sources'=>['Naukri','LinkedIn','Employee Referral'],'applicant_count'=>58],
            ['title'=>'HR Business Partner','department'=>'HR','location'=>'Delhi','job_type'=>'Full-time','posting_type'=>'Both','description'=>'Manage end-to-end HR operations.','requirements'=>'MBA HR, 4+ years experience','salary_from'=>600000,'salary_to'=>1000000,'number_of_openings'=>1,'closing_date'=>'2025-07-10','status'=>'Active','sources'=>['Career Page','LinkedIn'],'applicant_count'=>22],
            ['title'=>'Product Manager','department'=>'Product','location'=>'Bangalore','job_type'=>'Full-time','posting_type'=>'Internal','description'=>'Own the product roadmap.','requirements'=>'3+ years PM experience, Agile','salary_from'=>1200000,'salary_to'=>2000000,'number_of_openings'=>1,'closing_date'=>'2025-07-15','status'=>'Draft','sources'=>['Internal Portal'],'applicant_count'=>0],
            ['title'=>'Financial Analyst','department'=>'Finance','location'=>'Pune','job_type'=>'Full-time','posting_type'=>'External','description'=>'Analyze financial data and prepare reports.','requirements'=>'CA/MBA Finance, 3+ years','salary_from'=>700000,'salary_to'=>1100000,'number_of_openings'=>2,'closing_date'=>'2025-07-08','status'=>'Active','sources'=>['Naukri','LinkedIn'],'applicant_count'=>41],
            ['title'=>'DevOps Engineer','department'=>'Engineering','location'=>'Remote','job_type'=>'Remote','posting_type'=>'Both','description'=>'Build and maintain CI/CD pipelines.','requirements'=>'3+ years DevOps, AWS, Docker, Kubernetes','salary_from'=>900000,'salary_to'=>1600000,'number_of_openings'=>2,'closing_date'=>'2025-06-20','status'=>'Closed','sources'=>['LinkedIn','Career Page'],'applicant_count'=>67],
        ];
        foreach ($jobs as $j) { HrJobPosting::create($j); }

        // ── Manpower Requests ───────────────────────────────────────────
        $requests = [
            ['department'=>'Engineering','position_title'=>'Senior React Developer','number_of_posts'=>2,'priority'=>'High','status'=>'Approved','job_type'=>'Full-time','justification'=>'Critical product deadline approaching'],
            ['department'=>'Sales','position_title'=>'Sales Executive','number_of_posts'=>3,'priority'=>'Medium','status'=>'Pending','job_type'=>'Full-time','justification'=>'Q3 expansion target requires additional sales force'],
            ['department'=>'HR','position_title'=>'HR Business Partner','number_of_posts'=>1,'priority'=>'Low','status'=>'Approved','job_type'=>'Full-time','justification'=>'Support growing workforce of 150+ employees'],
            ['department'=>'Operations','position_title'=>'Operations Manager','number_of_posts'=>1,'priority'=>'High','status'=>'Rejected','job_type'=>'Full-time','justification'=>'Budget not approved by management'],
            ['department'=>'Finance','position_title'=>'Financial Analyst','number_of_posts'=>2,'priority'=>'Medium','status'=>'Pending','job_type'=>'Full-time','justification'=>'Audit season staffing requirement'],
            ['department'=>'Marketing','position_title'=>'Content Strategist','number_of_posts'=>1,'priority'=>'Low','status'=>'Pending','job_type'=>'Part-time','justification'=>'Brand awareness campaign'],
            ['department'=>'Product','position_title'=>'Product Manager','number_of_posts'=>1,'priority'=>'High','status'=>'Approved','job_type'=>'Full-time','justification'=>'New product line launch in Q4'],
            ['department'=>'Engineering','position_title'=>'DevOps Engineer','number_of_posts'=>2,'priority'=>'Medium','status'=>'Pending','job_type'=>'Full-time','justification'=>'Infrastructure scaling needs'],
        ];
        foreach ($requests as $r) { HrManpowerRequest::create($r); }

        // ── Candidates ──────────────────────────────────────────────────
        $candidates = [
            ['name'=>'Arjun Sharma','email'=>'arjun.sharma@gmail.com','phone'=>'+91 98765 43210','location'=>'Bangalore','current_company'=>'TechCorp India','experience_years'=>5.0,'source'=>'LinkedIn','stage'=>'Interview','job_posting_id'=>1,'ai_score'=>87,'skills'=>['React.js','TypeScript','Node.js','GraphQL','AWS'],'linkedin_url'=>'https://linkedin.com/in/arjun-sharma-dev'],
            ['name'=>'Divya Nair','email'=>'divya.nair@gmail.com','phone'=>'+91 98765 11111','location'=>'Mumbai','current_company'=>'SalesForce India','experience_years'=>3.5,'source'=>'Naukri','stage'=>'Offer','job_posting_id'=>2,'ai_score'=>91,'skills'=>['B2B Sales','CRM','Negotiation','Salesforce'],'linkedin_url'=>null],
            ['name'=>'Rohan Verma','email'=>'rohan.verma@gmail.com','phone'=>'+91 98765 22222','location'=>'Pune','current_company'=>'Deloitte','experience_years'=>4.0,'source'=>'Career Page','stage'=>'Screening','job_posting_id'=>5,'ai_score'=>74,'skills'=>['Financial Analysis','Excel','SAP','Python'],'linkedin_url'=>null],
            ['name'=>'Priya Mehta','email'=>'priya.mehta@gmail.com','phone'=>'+91 98765 33333','location'=>'Delhi','current_company'=>'Wipro HR','experience_years'=>5.5,'source'=>'Employee Referral','stage'=>'Assessment','job_posting_id'=>3,'ai_score'=>82,'skills'=>['HRIS','Recruiting','Labor Law','Performance Management'],'linkedin_url'=>null],
            ['name'=>'Karan Singh','email'=>'karan.singh@gmail.com','phone'=>'+91 98765 44444','location'=>'Bangalore','current_company'=>'Infosys Cloud','experience_years'=>4.5,'source'=>'LinkedIn','stage'=>'Hired','job_posting_id'=>6,'ai_score'=>95,'skills'=>['Docker','Kubernetes','AWS','Terraform','CI/CD'],'linkedin_url'=>'https://linkedin.com/in/karan-singh-devops'],
            ['name'=>'Anjali Gupta','email'=>'anjali.gupta@gmail.com','phone'=>'+91 98765 55555','location'=>'Bangalore','current_company'=>'Freshworks','experience_years'=>2.0,'source'=>'Internal Portal','stage'=>'Applied','job_posting_id'=>4,'ai_score'=>69,'skills'=>['Product Management','Agile','JIRA','User Research'],'linkedin_url'=>null],
            ['name'=>'Vikram Patel','email'=>'vikram.patel@gmail.com','phone'=>'+91 98765 66666','location'=>'Hyderabad','current_company'=>'Capgemini','experience_years'=>6.0,'source'=>'LinkedIn','stage'=>'Applied','job_posting_id'=>1,'ai_score'=>78,'skills'=>['React.js','Angular','Vue.js','REST APIs'],'linkedin_url'=>'https://linkedin.com/in/vikram-patel-fe'],
            ['name'=>'Sneha Iyer','email'=>'sneha.iyer@gmail.com','phone'=>'+91 98765 77777','location'=>'Chennai','current_company'=>'HCL','experience_years'=>2.5,'source'=>'Naukri','stage'=>'Screening','job_posting_id'=>2,'ai_score'=>65,'skills'=>['Sales','Lead Generation','Cold Calling','Salesforce'],'linkedin_url'=>null],
            ['name'=>'Pooja Sharma','email'=>'pooja.sharma@gmail.com','phone'=>'+91 98765 88888','location'=>'Gurgaon','current_company'=>'Accenture HR','experience_years'=>5.0,'source'=>'LinkedIn','stage'=>'Interview','job_posting_id'=>3,'ai_score'=>88,'skills'=>['HR Strategy','Talent Acquisition','HRBP','Succession Planning'],'linkedin_url'=>null],
            ['name'=>'Rahul Joshi','email'=>'rahul.joshi@gmail.com','phone'=>'+91 98765 99999','location'=>'Pune','current_company'=>'Persistent Systems','experience_years'=>4.0,'source'=>'LinkedIn','stage'=>'Interview','job_posting_id'=>1,'ai_score'=>83,'skills'=>['React.js','Redux','Node.js','MongoDB'],'linkedin_url'=>'https://linkedin.com/in/rahul-joshi-react'],
            ['name'=>'Meena Reddy','email'=>'meena.reddy@gmail.com','phone'=>'+91 98765 10101','location'=>'Hyderabad','current_company'=>'Freshdesk','experience_years'=>3.0,'source'=>'Walk-in','stage'=>'Assessment','job_posting_id'=>3,'ai_score'=>76,'skills'=>['HR Operations','Payroll','Compliance','HRIS'],'linkedin_url'=>null],
            ['name'=>'Sunita Rao','email'=>'sunita.rao@gmail.com','phone'=>'+91 98765 20202','location'=>'Bangalore','current_company'=>'Oracle India','experience_years'=>7.0,'source'=>'Internal Portal','stage'=>'Hired','job_posting_id'=>4,'ai_score'=>90,'skills'=>['Product Strategy','OKRs','Analytics','Roadmapping'],'linkedin_url'=>null],
        ];
        foreach ($candidates as $c) { HrCandidate::create($c); }

        // ── Interview Rounds for Arjun Sharma (id=1) ───────────────────
        $rounds = [
            ['candidate_id'=>1,'round_name'=>'HR Telephonic','interviewer_name'=>'Sunita Rao','scheduled_at'=>'2025-06-14 10:00:00','status'=>'Completed','result'=>'Passed','notes'=>'Good communication, culturally fit. Positive attitude.','meet_link'=>null],
            ['candidate_id'=>1,'round_name'=>'Technical L1','interviewer_name'=>'Vikram Singh','scheduled_at'=>'2025-06-17 14:00:00','status'=>'Completed','result'=>'Passed','notes'=>'Strong in React, excellent problem solving. React hooks usage is impressive.','meet_link'=>'https://meet.google.com/abc-xyz-123'],
            ['candidate_id'=>1,'round_name'=>'Manager L2','interviewer_name'=>'Deepak Iyer','scheduled_at'=>'2025-06-20 11:00:00','status'=>'Completed','result'=>'Passed','notes'=>'Leadership potential observed. Good system design knowledge.','meet_link'=>'https://meet.google.com/def-uvw-456'],
            ['candidate_id'=>1,'round_name'=>'Final HR L3','interviewer_name'=>'Sonal Mehta','scheduled_at'=>'2025-06-24 15:00:00','status'=>'Scheduled','result'=>'Pending','notes'=>null,'meet_link'=>'https://meet.google.com/ghi-rst-789'],
        ];
        foreach ($rounds as $r) { HrInterviewRound::create($r); }

        // Rounds for Pooja Sharma (id=9)
        HrInterviewRound::create(['candidate_id'=>9,'round_name'=>'HR Telephonic','interviewer_name'=>'Amit Kumar','scheduled_at'=>'2025-06-18 10:00:00','status'=>'Completed','result'=>'Passed','notes'=>'Excellent communication.']);
        HrInterviewRound::create(['candidate_id'=>9,'round_name'=>'Technical L1','interviewer_name'=>'Sunita Rao','scheduled_at'=>'2025-06-24 12:00:00','status'=>'Scheduled','result'=>'Pending','meet_link'=>'https://meet.google.com/jkl-mno-012','notes'=>null]);

        // ── Offers ──────────────────────────────────────────────────────
        HrOffer::create(['candidate_id'=>2,'position'=>'Sales Executive','department'=>'Sales','offered_ctc'=>600000,'joining_date'=>'2025-08-01','probation_period'=>'3 months','notice_period'=>'1 month','validity_date'=>'2025-07-15','status'=>'Accepted','sent_at'=>now()->subDays(5),'accepted_at'=>now()->subDays(2)]);
        HrOffer::create(['candidate_id'=>5,'position'=>'DevOps Engineer','department'=>'Engineering','offered_ctc'=>1200000,'joining_date'=>'2025-08-15','probation_period'=>'3 months','notice_period'=>'1 month','validity_date'=>'2025-07-20','status'=>'Accepted','sent_at'=>now()->subDays(10),'accepted_at'=>now()->subDays(8)]);
        HrOffer::create(['candidate_id'=>12,'position'=>'Product Manager','department'=>'Product','offered_ctc'=>1600000,'joining_date'=>'2025-09-01','probation_period'=>'6 months','notice_period'=>'2 months','validity_date'=>'2025-07-25','status'=>'Sent','sent_at'=>now()->subDays(1)]);

        // ── Onboarding ──────────────────────────────────────────────────
        HrOnboarding::create(['candidate_id'=>2,'candidate_name'=>'Divya Nair','position'=>'Sales Executive','joining_date'=>'2025-08-01','department'=>'Sales','step_doc_verification'=>true,'step_joining_confirmed'=>true,'step_emp_id_generated'=>true,'step_dept_assigned'=>true,'step_manager_assigned'=>false,'step_record_created'=>false,'status'=>'In Progress']);
        HrOnboarding::create(['candidate_id'=>5,'candidate_name'=>'Karan Singh','position'=>'DevOps Engineer','joining_date'=>'2025-08-15','department'=>'Engineering','employee_code'=>'SNE-2025-001','step_doc_verification'=>true,'step_joining_confirmed'=>true,'step_emp_id_generated'=>true,'step_dept_assigned'=>true,'step_manager_assigned'=>true,'step_record_created'=>true,'status'=>'Completed']);
        HrOnboarding::create(['candidate_id'=>12,'candidate_name'=>'Sunita Rao','position'=>'Product Manager','joining_date'=>'2025-09-01','department'=>'Product','employee_code'=>'SNE-2025-002','step_doc_verification'=>true,'step_joining_confirmed'=>true,'step_emp_id_generated'=>true,'step_dept_assigned'=>true,'step_manager_assigned'=>true,'step_record_created'=>true,'status'=>'Completed']);

        // ── Employees ───────────────────────────────────────────────────
        $employees = [
            ['employee_code'=>'SNE-2025-001','name'=>'Karan Singh','email'=>'karan.singh@company.com','phone'=>'+91 98765 44444','department'=>'Engineering','designation'=>'Senior DevOps Engineer','reporting_manager_name'=>'Vikram Singh','joining_date'=>'2025-08-15','status'=>'Active','candidate_id'=>5],
            ['employee_code'=>'SNE-2025-002','name'=>'Sunita Rao','email'=>'sunita.rao@company.com','phone'=>'+91 98765 20202','department'=>'Product','designation'=>'Product Manager','reporting_manager_name'=>'Ravi Iyer','joining_date'=>'2025-09-01','status'=>'Active','candidate_id'=>12],
            ['employee_code'=>'SNE-2024-010','name'=>'Rahul Shah','email'=>'rahul.shah@company.com','phone'=>'+91 98765 55555','department'=>'Engineering','designation'=>'Engineering Manager','reporting_manager_name'=>'Deepak Iyer','joining_date'=>'2024-01-01','status'=>'Active','candidate_id'=>null],
            ['employee_code'=>'SNE-2024-011','name'=>'Priya Mehta','email'=>'priya.mehta@company.com','phone'=>'+91 98765 66666','department'=>'Sales','designation'=>'Sales Manager','reporting_manager_name'=>'Sanjay Patel','joining_date'=>'2024-01-15','status'=>'Active','candidate_id'=>null],
            ['employee_code'=>'SNE-2024-012','name'=>'Amit Kumar','email'=>'amit.kumar@company.com','phone'=>'+91 98765 77777','department'=>'HR','designation'=>'HR Manager','reporting_manager_name'=>'Sonal Mehta','joining_date'=>'2024-02-01','status'=>'Active','candidate_id'=>null],
            ['employee_code'=>'SNE-2024-013','name'=>'Neha Joshi','email'=>'neha.joshi@company.com','phone'=>'+91 98765 88888','department'=>'Operations','designation'=>'Operations Head','reporting_manager_name'=>'Deepak Iyer','joining_date'=>'2024-03-01','status'=>'On Leave','candidate_id'=>null],
            ['employee_code'=>'SNE-2023-005','name'=>'Vikram Singh','email'=>'vikram.singh@company.com','phone'=>'+91 98765 99999','department'=>'Engineering','designation'=>'Principal Engineer','reporting_manager_name'=>'Rahul Shah','joining_date'=>'2023-06-01','status'=>'Active','candidate_id'=>null],
            ['employee_code'=>'SNE-2023-006','name'=>'Deepak Iyer','email'=>'deepak.iyer@company.com','phone'=>'+91 98765 00001','department'=>'Operations','designation'=>'COO','reporting_manager_name'=>'CEO','joining_date'=>'2023-01-01','status'=>'Active','candidate_id'=>null],
        ];
        foreach ($employees as $e) { HrEmployee::create($e); }

        $this->command->info('✅ HR Module seeded successfully!');
    }
}
