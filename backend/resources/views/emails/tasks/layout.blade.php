@php
    // Deep links must point at the SPA, not the API — see config/tasks.php.
    $link = rtrim(config('tasks.app_url'), '/').'/app/tasks/'.$task->id;
    $accent  = $accent  ?? '#7c3aed';
    $accent2 = $accent2 ?? '#8b5cf6';
    // "Website › Design › Homepage mockup" — everything above this task. The last
    // entry IS this task, so the trail on its own tells you where you are.
    $trail = collect($ancestry ?? [])->pluck('name')->all();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $headline }}</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9; padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="620" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 6px 24px rgba(15,23,42,0.08);">
                <tr>
                    <td style="background:linear-gradient(135deg,{{ $accent }},{{ $accent2 }}); padding:26px 32px;">
                        <p style="margin:0; color:#ffffff; font-size:19px; font-weight:bold;">{{ $headline }}</p>
                        @if (count($trail) > 1)
                            <p style="margin:6px 0 0; color:rgba(255,255,255,0.85); font-size:12px;">
                                {{ implode(' › ', array_slice($trail, 0, -1)) }}
                            </p>
                        @endif
                    </td>
                </tr>

                <tr>
                    <td style="padding:26px 32px 0;">
                        <p style="margin:0; font-size:14px; color:#334155; line-height:1.65;">{{ $intro }}</p>
                    </td>
                </tr>

                {{-- The task itself: name, and the three facts that make a subtask
                     a real task rather than a checklist line. --}}
                <tr>
                    <td style="padding:20px 32px 4px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                            style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px;">
                            <tr>
                                <td style="padding:16px 18px;">
                                    <p style="margin:0 0 10px; font-size:16px; font-weight:bold; color:#0f172a;">{{ $task->name }}</p>
                                    <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:12px;">
                                        <tr>
                                            <td style="padding:3px 16px 3px 0; color:#64748b;">Deadline</td>
                                            <td style="padding:3px 0; color:#0f172a;">
                                                {{ $task->due_date ? $task->due_date->format('d M Y') : 'none set' }}
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding:3px 16px 3px 0; color:#64748b;">Priority</td>
                                            <td style="padding:3px 0; color:#0f172a;">{{ ucfirst($task->priority ?: 'medium') }}</td>
                                        </tr>
                                        @if (count($trail) > 1)
                                            <tr>
                                                <td style="padding:3px 16px 3px 0; color:#64748b;">Sits under</td>
                                                <td style="padding:3px 0; color:#0f172a;">{{ implode(' › ', array_slice($trail, 0, -1)) }}</td>
                                            </tr>
                                        @endif
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                @yield('extra')

                <tr>
                    <td style="padding:20px 32px 28px;">
                        <a href="{{ $link }}" style="display:inline-block; background:{{ $accent }}; color:#ffffff; text-decoration:none; font-size:13px; font-weight:bold; padding:11px 22px; border-radius:10px;">
                            Open the task
                        </a>
                    </td>
                </tr>

                <tr>
                    <td style="background:#f8fafc; padding:16px 32px; border-top:1px solid #e2e8f0;">
                        <p style="margin:0; font-size:11px; color:#94a3b8;">
                            Automatic notice from the Tasks module. Turn these off in Tasks &rsaquo; Notifications.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
