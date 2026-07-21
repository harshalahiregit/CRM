@extends('emails.tasks.layout', [
    'accent'  => $overdue ? '#dc2626' : '#b45309',
    'accent2' => $overdue ? '#ef4444' : '#f59e0b',
])

@section('extra')
    <tr>
        <td style="padding:16px 32px 0;">
            <p style="margin:0; font-size:12px; color:#64748b; line-height:1.6;">
                @if (count($ancestry ?? []) > 1)
                    This is a subtask with its own deadline — it does not share the one on the task above it,
                    so finishing the parent will not clear this.
                @else
                    Subtasks underneath this one carry their own deadlines and are not covered by this notice.
                @endif
            </p>
        </td>
    </tr>
@endsection
