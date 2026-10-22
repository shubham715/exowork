<?php

namespace App\Services;

class WhatsAppTemplateCatalog
{
    public static function all(): array
    {
        $rows = [
            ['welcome', 'Registration confirmation & profile review', 'Candidate', 'Once after consented registration and account activation', 'UTILITY', 'en_US', 'Hello {{1}},

*Your EXOWORK registration is complete.*

*Next step:* Review your candidate profile and update any missing or incorrect details.
{{2}}

Reply STOP to stop WhatsApp messages.', ['Candidate name', 'Profile URL'], ['Asha Sharma', 'https://exowork.in/candidate/profile']],
            ['career_intro', 'Career introduction', 'Candidate', 'Interested and ready for placement', 'MARKETING', 'en_US', 'Hello {{1}},

*Your EXOWORK career introduction*

Watch the introduction:
{{2}}

*Explore opportunities:*
{{3}}

Reply STOP to opt out.', ['Candidate name', 'Career video URL', 'Opportunities URL'], ['Asha Sharma', 'https://example.com/career-video', 'https://example.com/candidate/opportunities']],
            ['training_reconnect', 'Reconnect near batch completion', 'Candidate', 'Interested; batch ends more than 15 days away', 'UTILITY', 'en_US', 'Hello {{1}},

*Your training batch update*

*Expected completion:* {{2}}
EXOWORK will reconnect near completion.

*Review your availability:*
{{3}}

Reply STOP to opt out.', ['Candidate name', 'Batch end date', 'Profile URL'], ['Asha Sharma', '30 October 2026', 'https://example.com/candidate/profile']],
            ['job_opportunity', 'Anonymous job opportunity', 'Candidate', 'Eligible active job recommendation; company hidden', 'MARKETING', 'en_US', 'Hello {{1}},

*New job opportunity from EXOWORK*

*Role:* {{2}}
*Location:* {{3}}
*Monthly salary:* {{4}}

*Review the role and register interest:*
{{5}}

Employer details will be shared after interview confirmation.
Reply STOP to opt out.', ['Candidate name', 'Job role', 'Location', 'Salary', 'Opportunities URL'], ['Asha Sharma', 'Machine Operator', 'Jaipur', 'INR 18,000 - 22,000', 'https://example.com/candidate/opportunities']],
            ['interview_availability', 'Interview availability', 'Candidate', 'After candidate registers interest in a job', 'UTILITY', 'en_US', 'Hello {{1}},

*Interview availability for your application*

*Role:* {{2}}
We received your interest in this role.

*Next step:* Choose availability within 3 days, 7 days or later:
{{3}}

Our EXOWORK coordinator will call to finalise your schedule.
Reply STOP to opt out.', ['Candidate name', 'Job role', 'Interests URL'], ['Asha Sharma', 'Machine Operator', 'https://example.com/candidate/interests']],
            ['interview_scheduled', 'Confirmed interview details', 'Candidate', 'Confirmed interview creation or schedule change', 'UTILITY', 'en_US', 'Hello {{1}},

*Your interview schedule*

*Role:* {{2}}
*Company:* {{3}}
*Date and time:* {{4}}
*Location:* {{5}}
*Representative:* {{6}}
*Monthly salary:* {{7}}

*Confirm attendance or request help:*
{{8}}

For a schedule change, request help in your account.
Reply STOP to opt out.', ['Candidate name', 'Job role', 'Company name', 'Interview date and time', 'Interview location', 'Company representative', 'Salary', 'Interviews URL'], ['Asha Sharma', 'Machine Operator', 'Example Industries', '25 October 2026, 10:00 AM IST', 'Jaipur office', 'Hiring coordinator', 'INR 20,000', 'https://exowork.in/candidate/interviews']],
            ['interview_reminder', 'Interview reminder (Hindi)', 'Candidate', 'One calendar day before interview', 'UTILITY', 'hi', 'नमस्ते {{1}},

*आपके इंटरव्यू का रिमाइंडर*

*पद:* {{2}}
*दिनांक और समय:* {{3}}

*क्या आप आ सकते हैं?*
अपने खाते में पुष्टि करें, सवाल बताएं या आने में असमर्थ चुनें:
{{4}}

सहायता के लिए EXOWORK टीम आपसे संपर्क करेगी।
संदेश बंद करने के लिए STOP भेजें।', ['Candidate name', 'Job role', 'Interview date and time', 'Interviews URL'], ['आशा शर्मा', 'मशीन ऑपरेटर', '08 October 2026, 10:00 AM IST', 'https://example.com/candidate/interviews']],
            ['selection_update', 'Selection decision', 'Candidate', 'Staff records selection or rejection', 'UTILITY', 'en_US', 'Hello {{1}},

*Your application status update*

*Role:* {{2}}
*Company:* {{3}}
*Status:* {{4}}

*View the confirmed decision and next steps:*
{{5}}

Reply STOP to opt out.', ['Candidate name', 'Job role', 'Company name', 'Decision', 'Joining page URL'], ['Asha Sharma', 'Machine Operator', 'Example Industries', 'Selected', 'https://example.com/candidate/joining']],
            ['joining_update', 'Joining update', 'Candidate', 'Joining pending or actual joining recorded', 'UTILITY', 'en_US', 'Hello {{1}},

*Your joining update*

*Role:* {{2}}
*Company:* {{3}}
*Status:* {{4}}
*Joining date:* {{5}}

*Review joining details or request help:*
{{6}}

Reply STOP to opt out.', ['Candidate name', 'Job role', 'Company name', 'Joining status', 'Joining date', 'Joining page URL'], ['Asha Sharma', 'Machine Operator', 'Example Industries', 'Joining pending', '12 October 2026', 'https://example.com/candidate/joining']],
            ['center_placement_update', 'Center placement notification', 'Training center SPOC', 'Interview, selection or joining status changes', 'UTILITY', 'en_US', 'Hello {{1}},

*EXOWORK placement update*

*Candidate:* {{2}} ({{3}})
*Role:* {{4}}
*Company:* {{5}}
*Update:* {{6}}
*Schedule/date:* {{7}}

*Review your center workspace:*
{{8}}

Reply STOP to opt out.', ['Center name', 'Candidate name', 'Candidate code', 'Job role', 'Company name', 'Update', 'Schedule or date', 'Center workspace URL'], ['Jaipur Training Center', 'Asha Sharma', 'EXO-CAN-000001', 'Machine Operator', 'Example Industries', 'Interview scheduled', '08 October 2026, 10:00 AM IST', 'https://example.com/center/interviews']],
        ];
        foreach ([7, 15, 30] as $day) {
            $rows[] = ['retention_day_'.$day, 'Day '.$day.' retention check', 'Candidate', 'Day '.$day.' after actual joining', 'UTILITY', 'en_US', 'Hello {{1}},

*Day '.$day.' employment check-in*

*Company:* {{2}}
*Role:* {{3}}

Please confirm whether you are still employed, need assistance or have left.

*Update your employment status:*
{{4}}

Our EXOWORK team will follow up if you need help.
Reply STOP to opt out.', ['Candidate name', 'Company name', 'Job role', 'Retention page URL'], ['Asha Sharma', 'Example Industries', 'Machine Operator', 'https://example.com/candidate/retention']];
        }
        return array_map(fn ($r) => ['key' => $r[0], 'name' => $r[0] === 'welcome' ? 'exowork_registration_v3' : 'exowork_'.$r[0].'_v2', 'title' => $r[1], 'audience' => $r[2], 'usage' => $r[3], 'category' => $r[4], 'language' => $r[5], 'body' => str_replace("\r\n", "\n", $r[6]), 'labels' => $r[7], 'examples' => array_map(fn ($v) => str_replace('https://example.com', 'https://exowork.in', $v), $r[8])], $rows);
    }

    public static function get(string $key): array
    {
        return collect(self::all())->firstWhere('key', $key) ?? abort(404);
    }
}
