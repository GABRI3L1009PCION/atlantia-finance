<!DOCTYPE html>
<html lang="es">
<body style="margin: 0; padding: 24px 12px; background: #f5f1ef; font-family: Arial, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden;">
        <tr><td style="background: {{ $identity['color'] }}; color: #ffffff; padding: 18px 24px;">
            <div style="font-size: 17px; font-weight: bold;">{{ $identity['company'] ?? $identity['firm'] }}</div>
            @if ($identity['company'] !== null)
                <div style="font-size: 12px; opacity: 0.85;">Gestión de cobros por {{ $identity['firm'] }}</div>
            @endif
        </td></tr>
        <tr><td style="padding: 24px;">
            {{-- Lines starting with "- " (the documents of the template) become a table; the rest stays as paragraphs. --}}
            @php($blocks = [])
            @foreach (preg_split('/\R/', trim($body)) as $line)
                @php($isItem = str_starts_with(trim($line), '- '))
                @php($kind = $isItem ? 'items' : (trim($line) === '' ? 'gap' : 'text'))
                @if ($blocks !== [] && end($blocks)['kind'] === $kind && $kind !== 'gap')
                    @php($blocks[array_key_last($blocks)]['lines'][] = $isItem ? substr(trim($line), 2) : $line)
                @else
                    @php($blocks[] = ['kind' => $kind, 'lines' => [$isItem ? substr(trim($line), 2) : $line]])
                @endif
            @endforeach
            @foreach ($blocks as $block)
                @if ($block['kind'] === 'items')
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin: 0 0 14px; border: 1px solid #ece4e0; border-radius: 8px;">
                        @foreach ($block['lines'] as $item)
                            <tr><td style="padding: 9px 12px; border-bottom: 1px solid #f1ebe8; font-size: 13px;">{{ $item }}</td></tr>
                        @endforeach
                    </table>
                @elseif ($block['kind'] === 'text')
                    <p style="margin: 0 0 14px;">{!! nl2br(e(implode("\n", $block['lines']))) !!}</p>
                @endif
            @endforeach
        </td></tr>
        <tr><td style="padding: 16px 24px; background: #faf7f5; color: #6b7280; font-size: 12px; border-top: 1px solid #ece4e0;">
            {{ $identity['firm'] }}@if ($identity['phone']) · Tel. {{ $identity['phone'] }}@endif
            @if ($identity['reply_to'])<br>Para cualquier consulta responda este correo o escriba a {{ $identity['reply_to'] }}.@endif
            <br>Si ya realizó su pago, por favor ignore este mensaje.
        </td></tr>
    </table>
</body>
</html>
