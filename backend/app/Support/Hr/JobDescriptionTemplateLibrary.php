<?php

namespace App\Support\Hr;

use App\Models\Hr\HrManpowerRequest;

/**
 * Role-based JD templates for the "Generate from Template" action — a
 * deterministic (no-LLM) alternative to AI generation. Each template supplies
 * role-appropriate defaults (summary, responsibilities, skills, qualification);
 * the renderer merges them with the requisition's real fields (title, company,
 * location, salary, skills). Output is the same clean plain-text format as the
 * AI generator, so it drops straight into the existing JD editor.
 *
 * Add a role by adding one entry to TEMPLATES — no service/controller change.
 */
class JobDescriptionTemplateLibrary
{
    public const TEMPLATES = [
        'software_developer' => [
            'label' => 'Software Developer',
            'summary' => 'design, build and maintain reliable software that powers our products',
            'responsibilities' => [
                'Design, develop and maintain clean, testable, well-documented code',
                'Collaborate with product, design and QA to ship features end-to-end',
                'Participate in code reviews and uphold engineering best practices',
                'Debug, profile and optimise applications for performance and scale',
                'Contribute to architecture discussions and technical decisions',
            ],
            'skills' => ['Data structures & algorithms', 'One or more modern programming languages', 'Version control (Git)', 'REST APIs', 'Relational databases'],
            'preferred' => ['Cloud platforms', 'CI/CD', 'Automated testing', 'Agile/Scrum'],
            'qualification' => "Bachelor's degree in Computer Science, Engineering or a related field (or equivalent practical experience).",
        ],
        'qa_engineer' => [
            'label' => 'QA Engineer',
            'summary' => 'safeguard product quality through rigorous testing and automation',
            'responsibilities' => [
                'Design, write and execute manual and automated test cases',
                'Build and maintain automated test suites and CI test pipelines',
                'Log, track and verify defects to closure with clear reproduction steps',
                'Own regression, functional, integration and API testing',
                'Partner with developers to embed quality early in the lifecycle',
            ],
            'skills' => ['Test case design', 'Automation (Selenium/Cypress/Playwright)', 'API testing (Postman)', 'Bug tracking (Jira)', 'SQL'],
            'preferred' => ['Performance testing', 'CI/CD', 'BDD frameworks', 'Basic scripting'],
            'qualification' => "Bachelor's degree in Computer Science, Engineering or equivalent, with a strong quality mindset.",
        ],
        'sales_executive' => [
            'label' => 'Sales Executive',
            'summary' => 'drive revenue by building relationships and closing new business',
            'responsibilities' => [
                'Identify, qualify and pursue new sales opportunities',
                'Own the full sales cycle from prospecting to close',
                'Build and maintain strong, long-lasting customer relationships',
                'Consistently meet or exceed monthly and quarterly targets',
                'Maintain accurate pipeline and forecasts in the CRM',
            ],
            'skills' => ['Negotiation', 'Communication & presentation', 'Lead generation', 'CRM tools', 'Relationship building'],
            'preferred' => ['B2B sales', 'Solution selling', 'Territory management'],
            'qualification' => "Bachelor's degree in Business, Marketing or related field; proven sales track record preferred.",
        ],
        'hr_recruiter' => [
            'label' => 'HR Recruiter',
            'summary' => 'attract and hire exceptional talent across the organisation',
            'responsibilities' => [
                'Own end-to-end recruitment from sourcing to offer',
                'Partner with hiring managers to understand role requirements',
                'Screen, interview and shortlist candidates',
                'Manage the candidate experience and employer brand',
                'Track pipeline metrics and time-to-hire',
            ],
            'skills' => ['Sourcing', 'Interviewing', 'ATS tools', 'Stakeholder management', 'Communication'],
            'preferred' => ['Technical recruiting', 'Employer branding', 'HR analytics'],
            'qualification' => "Bachelor's degree in HR, Business or related field.",
        ],
        'project_manager' => [
            'label' => 'Project Manager',
            'summary' => 'plan, execute and deliver projects on time, scope and budget',
            'responsibilities' => [
                'Define project scope, goals, timelines and deliverables',
                'Coordinate cross-functional teams and manage dependencies',
                'Track progress, risks and budgets; report to stakeholders',
                'Remove blockers and keep delivery on track',
                'Run agile ceremonies and continuous improvement',
            ],
            'skills' => ['Project planning', 'Stakeholder management', 'Agile/Scrum', 'Risk management', 'Communication'],
            'preferred' => ['PMP/CSM certification', 'Budgeting', 'Tools (Jira/MS Project)'],
            'qualification' => "Bachelor's degree; project management certification (PMP/CSM) is a plus.",
        ],
        'uiux_designer' => [
            'label' => 'UI/UX Designer',
            'summary' => 'craft intuitive, delightful and accessible product experiences',
            'responsibilities' => [
                'Design user flows, wireframes, prototypes and high-fidelity UI',
                'Conduct user research and usability testing',
                'Maintain and evolve the design system',
                'Collaborate closely with product and engineering',
                'Advocate for the user and accessibility standards',
            ],
            'skills' => ['Figma/Sketch', 'Wireframing & prototyping', 'User research', 'Design systems', 'Visual design'],
            'preferred' => ['Motion design', 'HTML/CSS literacy', 'Accessibility (WCAG)'],
            'qualification' => "Degree in Design, HCI or related field, or an equivalent portfolio.",
        ],
        'business_analyst' => [
            'label' => 'Business Analyst',
            'summary' => 'translate business needs into clear, actionable requirements',
            'responsibilities' => [
                'Gather, analyse and document business requirements',
                'Map current and future-state processes',
                'Bridge stakeholders and technical teams',
                'Define acceptance criteria and support UAT',
                'Analyse data to inform decisions',
            ],
            'skills' => ['Requirements gathering', 'Process mapping', 'SQL', 'Documentation', 'Stakeholder communication'],
            'preferred' => ['BI tools', 'Agile', 'Data visualisation'],
            'qualification' => "Bachelor's degree in Business, IT or related field.",
        ],
        'devops_engineer' => [
            'label' => 'DevOps Engineer',
            'summary' => 'automate, scale and safeguard our infrastructure and delivery',
            'responsibilities' => [
                'Build and maintain CI/CD pipelines',
                'Manage cloud infrastructure as code',
                'Monitor, alert and improve system reliability',
                'Automate deployments, scaling and backups',
                'Champion security and cost optimisation',
            ],
            'skills' => ['CI/CD', 'Docker & Kubernetes', 'Cloud (AWS/Azure/GCP)', 'Infrastructure as Code (Terraform)', 'Linux & scripting'],
            'preferred' => ['Observability tooling', 'Security best practices', 'Networking'],
            'qualification' => "Bachelor's degree in Computer Science or equivalent hands-on experience.",
        ],
        'backend_developer' => [
            'label' => 'Backend Developer',
            'summary' => 'build robust, secure and scalable server-side systems and APIs',
            'responsibilities' => [
                'Design and implement APIs, services and data models',
                'Ensure performance, security and scalability of backend systems',
                'Write clean, well-tested, maintainable code',
                'Integrate databases, queues and third-party services',
                'Collaborate on architecture and code reviews',
            ],
            'skills' => ['Server-side language (PHP/Node/Java/Python)', 'REST/GraphQL APIs', 'Databases (SQL/NoSQL)', 'Caching & queues', 'Testing'],
            'preferred' => ['Microservices', 'Cloud', 'Event-driven design', 'Docker'],
            'qualification' => "Bachelor's degree in Computer Science or equivalent practical experience.",
        ],
        'frontend_developer' => [
            'label' => 'Frontend Developer',
            'summary' => 'build fast, accessible and beautiful user interfaces',
            'responsibilities' => [
                'Build responsive, accessible UI from designs',
                'Develop reusable components and front-end architecture',
                'Optimise for performance and cross-browser support',
                'Integrate with REST/GraphQL APIs',
                'Collaborate with designers and backend engineers',
            ],
            'skills' => ['HTML, CSS, JavaScript', 'A modern framework (React/Vue/Angular)', 'State management', 'Responsive design', 'REST APIs'],
            'preferred' => ['TypeScript', 'Testing (Jest/Cypress)', 'Build tooling', 'Accessibility'],
            'qualification' => "Bachelor's degree or equivalent practical front-end experience.",
        ],
    ];

    /** [{key,label}] for the picker. */
    public static function catalog(): array
    {
        return array_map(fn ($k, $t) => ['key' => $k, 'label' => $t['label']], array_keys(self::TEMPLATES), array_values(self::TEMPLATES));
    }

    public static function has(string $key): bool
    {
        return isset(self::TEMPLATES[$key]);
    }

    /** Render a full JD (plain text, 11 sections) from a role template + the requisition. */
    public static function render(HrManpowerRequest $mr, string $key, ?string $companyName = null): string
    {
        $t = self::TEMPLATES[$key];
        $company = $companyName ?: 'our organisation';
        $title = $mr->position_title ?: $t['label'];
        $location = $mr->location ?: 'To be specified';

        // Merge requisition skills with the template's role defaults (requisition first).
        $reqSkills = self::arr($mr->required_skills);
        $skills = array_values(array_unique(array_merge($reqSkills, $t['skills'])));
        $preferred = array_values(array_unique(array_merge(self::arr($mr->preferred_skills), $t['preferred'])));

        $out = [];
        $out[] = 'About Company';
        $out[] = "{$company} is a growth-focused organisation. We are hiring a {$title}"
            .($mr->department ? " in our {$mr->department} team" : '').'.';
        $out[] = '';
        $out[] = 'About Role';
        $out[] = "As a {$title}, you will {$t['summary']}. This is a".(preg_match('/^[aeiou]/i', $mr->job_type ?? '') ? 'n' : '')
            ." {$mr->job_type} role based in {$location}.";
        $out[] = '';
        $out[] = 'Responsibilities';
        foreach ($t['responsibilities'] as $r) { $out[] = "• {$r}"; }
        $out[] = '';
        $out[] = 'Required Skills';
        foreach ($skills as $s) { $out[] = "• {$s}"; }
        $out[] = '';
        $out[] = 'Preferred Skills';
        foreach ($preferred as $s) { $out[] = "• {$s}"; }
        $out[] = '';
        $out[] = 'Qualification';
        $out[] = $mr->education ? $mr->education : $t['qualification'];
        $out[] = '';
        $out[] = 'Experience';
        $out[] = $mr->experience_required ? "We are looking for {$mr->experience_required} of relevant experience." : 'Relevant experience in a similar role is preferred.';
        $out[] = '';
        $out[] = 'Benefits';
        $salary = self::salary($mr);
        $out[] = 'We offer a competitive salary'.($salary ? " ({$salary})" : '')
            .', a collaborative culture, learning opportunities and standard employee benefits.';
        $out[] = '';
        $out[] = 'Work Mode';
        $out[] = trim(($mr->work_mode ? $mr->work_mode : 'On-site').($mr->shift ? " · {$mr->shift} shift" : '')).'.';
        $out[] = '';
        $out[] = 'Hiring Process';
        $out[] = 'Our process typically includes an initial screening, one or more interviews, and a final discussion before the offer.';
        $out[] = '';
        $out[] = 'Equal Opportunity Statement';
        $out[] = "{$company} is an equal-opportunity employer. We celebrate diversity and are committed to an inclusive environment for all employees, regardless of gender, age, background, religion or ability.";

        return implode("\n", $out);
    }

    private static function salary(HrManpowerRequest $mr): ?string
    {
        $min = $mr->salary_min ? (float) $mr->salary_min : null;
        $max = $mr->salary_max ? (float) $mr->salary_max : null;
        if (! $min && ! $max) {
            return null;
        }
        $fmt = fn ($n) => '₹'.number_format($n);

        return $min && $max ? $fmt($min).' – '.$fmt($max) : ($min ? 'from '.$fmt($min) : 'up to '.$fmt($max));
    }

    private static function arr($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value)));
        }

        return $value ? [trim((string) $value)] : [];
    }
}
