{{--
    The contract e-mail.

    Table layout and inline styles throughout, because that is what mail clients
    render: Outlook has no flexbox and strips a <style> block, so anything laid
    out the way the app is would arrive as a single unstyled column.

    The signing button is a table cell with a background colour rather than a
    styled <a>, for the same reason — and the raw URL is printed underneath it,
    because a client that strips the button must still leave a way through.
--}}
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 0;font-family:Arial,Helvetica,sans-serif;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e3e6eb;">

      <tr>
        <td style="padding:22px 26px;border-bottom:1px solid #eceef2;">
          {{-- embedData, not a data: URI. Gmail, Outlook and Apple Mail all
               strip `data:` image sources, so the mark arrived as a broken
               image. embedData attaches the bytes and references them by cid:,
               which is the one inline form mail clients do render — and, like
               the data URI, it fetches nothing remote. --}}
          @php $brandLogo = \App\Support\Brand::logoFile(); @endphp
          @if($brandLogo)
            <img src="{{ $message->embedData($brandLogo['data'], $brandLogo['name'], $brandLogo['mime']) }}"
                 alt="{{ config('app.name') }}" width="120"
                 style="height:34px;width:auto;display:block;margin-bottom:6px;border:0;outline:none;text-decoration:none;">
          @endif
          <div style="font-size:17px;font-weight:bold;color:#1f2430;">{{ config('app.name') }}</div>
          <div style="font-size:12px;color:#6b7280;margin-top:2px;">{{ $contract->reference_no }}</div>
        </td>
      </tr>

      <tr>
        <td style="padding:24px 26px;font-size:14px;color:#1f2430;line-height:1.65;">
          {!! $bodyHtml !!}

          <table width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0;border:1px solid #eceef2;border-radius:8px;">
            <tr>
              <td style="padding:14px 16px;font-size:13px;color:#1f2430;">
                <strong style="font-size:15px;">{{ $contract->title }}</strong><br>
                @if($contract->value)
                  <span style="color:#6b7280;">Value:</span>
                  {{ $contract->currency }} {{ number_format((float) $contract->value, 2) }}<br>
                @endif
                <span style="color:#6b7280;">Term:</span>
                {{ optional($contract->start_date)->format('d M Y') ?? '—' }}
                to {{ optional($contract->end_date)->format('d M Y') ?? 'open-ended' }}
              </td>
            </tr>
          </table>

          {{-- The button. A table cell, not a styled anchor. --}}
          <table cellpadding="0" cellspacing="0" style="margin:22px 0;">
            <tr>
              <td align="center" bgcolor="#7C3AED" style="border-radius:8px;">
                <a href="{{ $signUrl }}"
                   style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;">
                  Review &amp; sign the contract
                </a>
              </td>
            </tr>
          </table>

          <p style="font-size:12px;color:#6b7280;margin:0 0 4px;">
            If the button does not work, copy this address into your browser:
          </p>
          <p style="font-size:12px;color:#7C3AED;word-break:break-all;margin:0;">{{ $signUrl }}</p>

          <p style="font-size:12.5px;color:#6b7280;margin:22px 0 0;line-height:1.6;">
            A copy of the contract is attached to this e-mail. Your signature will be recorded
            with the date, time and network address it was given from, and the signed document
            carries a code anybody can use to check that it is genuine.
          </p>
        </td>
      </tr>

      <tr>
        <td style="padding:16px 26px;border-top:1px solid #eceef2;font-size:11.5px;color:#9aa2b1;">
          Sent by {{ config('app.name') }}. If you were not expecting this, please reply and let us know.
        </td>
      </tr>
    </table>
  </td></tr>
</table>
