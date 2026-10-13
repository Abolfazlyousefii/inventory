// Convert visible labels only. Payment values, document URLs and data stay unchanged.
(() => {
    const root = document.querySelector('.as-document');
    if (!root) return;
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        if (node.parentElement?.closest('script,style,textarea,input,[data-keep-digits]')) continue;
        node.nodeValue = node.nodeValue.replace(/[0-9]/g, digit => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);
    }
})();
