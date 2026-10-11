@php
    // Use the existing customer print unchanged. Only this entry point opens print automatically.
    $html = view('prints.invoice', compact('printData'))->render();
    $script = <<<'HTML'
<script>
window.addEventListener('load', async () => {
    if (document.fonts) await document.fonts.ready;
    await Promise.all(Array.from(document.images, image => image.decode ? image.decode().catch(() => {}) : Promise.resolve()));
    window.print();
}, {once: true});
</script>
HTML;
    $html = str_replace('</body>', $script.'</body>', $html);
@endphp
{!! $html !!}
