@extends('emails.tasks.layout', ['accent' => '#059669', 'accent2' => '#10B981'])

{{-- The point of this email is the parent's new number, so show the bar. --}}
@section('extra')
    @if (! empty($progress) && ($progress['total'] ?? 0) > 0)
        <tr>
            <td style="padding:18px 32px 0;">
                <p style="margin:0 0 8px; font-size:12px; color:#64748b;">
                    Progress on the task above: <strong style="color:#0f172a;">{{ $progress['percent'] }}%</strong>
                    &nbsp;({{ $progress['done'] }} of {{ $progress['total'] }} done)
                </p>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                    style="background:#e2e8f0; border-radius:999px; height:8px;">
                    <tr>
                        <td width="{{ max(2, (int) $progress['percent']) }}%"
                            style="background:#10B981; border-radius:999px; height:8px; font-size:0; line-height:0;">&nbsp;</td>
                        <td style="font-size:0; line-height:0;">&nbsp;</td>
                    </tr>
                </table>
            </td>
        </tr>
    @endif
@endsection
