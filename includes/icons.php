<?php
// SVG آیکون‌ها برای کل سایت

function svg_icon($name, $classes = '') {
    $icons = [
        'instagram' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="' . $classes . '"><path d="M7 2C4.243 2 2 4.243 2 7v10c0 2.757 2.243 5 5 5h10c2.757 0 5-2.243 5-5V7c0-2.757-2.243-5-5-5H7zm10 2c1.654 0 3 1.346 3 3v10c0 1.654-1.346 3-3 3H7c-1.654 0-3-1.346-3-3V7c0-1.654 1.346-3 3-3h10zm-5 3a5 5 0 100 10 5 5 0 000-10zm0 2a3 3 0 110 6 3 3 0 010-6zm4.5-.75a1.25 1.25 0 100 2.5 1.25 1.25 0 000-2.5z"/></svg>',
        'phone' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="' . $classes . '"><path d="M6.62 10.79a15.053 15.053 0 006.59 6.59l2.2-2.2a1 1 0 011.06-.24 11.72 11.72 0 003.66.58 1 1 0 011 1v3.5a1 1 0 01-1 1A17 17 0 013 5a1 1 0 011-1h3.5a1 1 0 011 1 11.72 11.72 0 00.58 3.66 1 1 0 01-.24 1.06l-2.2 2.2z"/></svg>',
        'map' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="' . $classes . '"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 110-5 2.5 2.5 0 010 5z"/></svg>',
        'bale' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" fill="currentColor" class="' . $classes . '"><rect width="100" height="100" rx="20" fill="#00c6e0"/><text x="50" y="65" font-size="60" font-weight="bold" text-anchor="middle" fill="#fff">ب</text></svg>',
        'rubika' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" fill="currentColor" class="' . $classes . '"><circle cx="50" cy="50" r="45" fill="#ff6b6b"/><text x="50" y="65" font-size="48" font-weight="bold" text-anchor="middle" fill="#fff">ر</text></svg>',
        // common action icons
        'eye' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><!--!Font Awesome Free v7.3.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2026 Fonticons, Inc.--><path d="M320 96C239.2 96 174.5 132.8 127.4 176.6C80.6 220.1 49.3 272 34.4 307.7C31.1 315.6 31.1 324.4 34.4 332.3C49.3 368 80.6 420 127.4 463.4C174.5 507.1 239.2 544 320 544C400.8 544 465.5 507.2 512.6 463.4C559.4 419.9 590.7 368 605.6 332.3C608.9 324.4 608.9 315.6 605.6 307.7C590.7 272 559.4 220 512.6 176.6C465.5 132.9 400.8 96 320 96zM176 320C176 240.5 240.5 176 320 176C399.5 176 464 240.5 464 320C464 399.5 399.5 464 320 464C240.5 464 176 399.5 176 320zM320 256C320 291.3 291.3 320 256 320C244.5 320 233.7 317 224.3 311.6C223.3 322.5 224.2 333.7 227.2 344.8C240.9 396 293.6 426.4 344.8 412.7C396 399 426.4 346.3 412.7 295.1C400.5 249.4 357.2 220.3 311.6 224.3C316.9 233.6 320 244.4 320 256z"/></svg>',
        'ellipsis' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" fill="currentColor"><path d="M96 320C96 289.1 121.1 264 152 264C182.9 264 208 289.1 208 320C208 350.9 182.9 376 152 376C121.1 376 96 350.9 96 320zM264 320C264 289.1 289.1 264 320 264C350.9 264 376 289.1 376 320C376 350.9 350.9 376 320 376C289.1 376 264 350.9 264 320zM488 264C518.9 264 544 289.1 544 320C544 350.9 518.9 376 488 376C457.1 376 432 350.9 432 320C432 289.1 457.1 264 488 264z"/></svg>',

        'edit' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><!--!Font Awesome Free v7.3.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2026 Fonticons, Inc.--><path d="M535.6 85.7C513.7 63.8 478.3 63.8 456.4 85.7L432 110.1L529.9 208L554.3 183.6C576.2 161.7 576.2 126.3 554.3 104.4L535.6 85.7zM236.4 305.7C230.3 311.8 225.6 319.3 222.9 327.6L193.3 416.4C190.4 425 192.7 434.5 199.1 441C205.5 447.5 215 449.7 223.7 446.8L312.5 417.2C320.7 414.5 328.2 409.8 334.4 403.7L496 241.9L398.1 144L236.4 305.7zM160 128C107 128 64 171 64 224L64 480C64 533 107 576 160 576L416 576C469 576 512 533 512 480L512 384C512 366.3 497.7 352 480 352C462.3 352 448 366.3 448 384L448 480C448 497.7 433.7 512 416 512L160 512C142.3 512 128 497.7 128 480L128 224C128 206.3 142.3 192 160 192L256 192C273.7 192 288 177.7 288 160C288 142.3 273.7 128 256 128L160 128z"/></svg>',
        'user' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><!--!Font Awesome Free v7.3.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2026 Fonticons, Inc.--><path d="M320 312C386.3 312 440 258.3 440 192C440 125.7 386.3 72 320 72C253.7 72 200 125.7 200 192C200 258.3 253.7 312 320 312zM290.3 368C191.8 368 112 447.8 112 546.3C112 562.7 125.3 576 141.7 576L498.3 576C514.7 576 528 562.7 528 546.3C528 447.8 448.2 368 349.7 368L290.3 368z"/></svg>',
        'user-doctor' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><!--!Font Awesome Free v7.3.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2026 Fonticons, Inc.--><path d="M320 72C253.7 72 200 125.7 200 192C200 258.3 253.7 312 320 312C386.3 312 440 258.3 440 192C440 125.7 386.3 72 320 72zM380 384.8C374.6 384.3 369 384 363.4 384L276.5 384C270.9 384 265.4 384.3 259.9 384.8L259.9 452.3C276.4 459.9 287.9 476.6 287.9 495.9C287.9 522.4 266.4 543.9 239.9 543.9C213.4 543.9 191.9 522.4 191.9 495.9C191.9 476.5 203.4 459.8 219.9 452.3L219.9 393.9C157 417 112 477.6 112 548.6C112 563.7 124.3 576 139.4 576L500.5 576C515.6 576 527.9 563.7 527.9 548.6C527.9 477.6 482.9 417.1 419.9 394L419.9 431.4C443.2 439.6 459.9 461.9 459.9 488L459.9 520C459.9 531 450.9 540 439.9 540C428.9 540 419.9 531 419.9 520L419.9 488C419.9 477 410.9 468 399.9 468C388.9 468 379.9 477 379.9 488L379.9 520C379.9 531 370.9 540 359.9 540C348.9 540 339.9 531 339.9 520L339.9 488C339.9 461.9 356.6 439.7 379.9 431.4L379.9 384.8z"/></svg>',

        'trash' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><!--!Font Awesome Free v7.3.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2026 Fonticons, Inc.--><path d="M232.7 69.9L224 96L128 96C110.3 96 96 110.3 96 128C96 145.7 110.3 160 128 160L512 160C529.7 160 544 145.7 544 128C544 110.3 529.7 96 512 96L416 96L407.3 69.9C402.9 56.8 390.7 48 376.9 48L263.1 48C249.3 48 237.1 56.8 232.7 69.9zM512 208L128 208L149.1 531.1C150.7 556.4 171.7 576 197 576L443 576C468.3 576 489.3 556.4 490.9 531.1L512 208z"/></svg>',

        // additional action icons
        'pdf' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 384 512"><path d="M64 0C28.7 0 0 28.7 0 64L0 448c0 35.3 28.7 64 64 64l256 0c35.3 0 64-28.7 64-64l0-288-128 0c-17.7 0-32-14.3-32-32L224 0 64 0zM256 0l0 128 128 0L256 0zM96 240c0-8.8 7.2-16 16-16l48 0c35.3 0 64 28.7 64 64s-28.7 64-64 64l-32 0 0 32c0 8.8-7.2 16-16 16s-16-7.2-16-16l0-64 0-80zm32 80l32 0c17.7 0 32-14.3 32-32s-14.3-32-32-32l-32 0 0 64zm96-16c0-35.3 28.7-64 64-64l16 0c8.8 0 16 7.2 16 16s-7.2 16-16 16l-16 0c-17.7 0-32 14.3-32 32l0 48c0 17.7 14.3 32 32 32l16 0c8.8 0 16 7.2 16 16s-7.2 16-16 16l-16 0c-35.3 0-64-28.7-64-64l0-48z"/></svg>',
        'plus' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path d="M256 80c0-17.7-14.3-32-32-32s-32 14.3-32 32l0 144L48 224c-17.7 0-32 14.3-32 32s14.3 32 32 32l144 0 0 144c0 17.7 14.3 32 32 32s32-14.3 32-32l0-144 144 0c17.7 0 32-14.3 32-32s-14.3-32-32-32l-144 0 0-144z"/></svg>',
        'download' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M288 32c0-17.7-14.3-32-32-32s-32 14.3-32 32l0 242.7-73.4-73.4c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3l128 128c12.5 12.5 32.8 12.5 45.3 0l128-128c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0L288 274.7 288 32zM64 352c-35.3 0-64 28.7-64 64l0 32c0 35.3 28.7 64 64 64l384 0c35.3 0 64-28.7 64-64l0-32c0-35.3-28.7-64-64-64l-38.1 0-25.9 25.9c-35 35-91.9 35-126.9 0L230.1 352 64 352z"/></svg>',
        'money' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512"><path d="M64 64C28.7 64 0 92.7 0 128L0 384c0 35.3 28.7 64 64 64l448 0c35.3 0 64-28.7 64-64l0-256c0-35.3-28.7-64-64-64L64 64zm64 320l-32 0 0-32 32 0 0 32zm0-256l-32 0 0-32 32 0 0 32zM512 320c0 35.3-28.7 64-64 64l-128 0c-35.3 0-64-28.7-64-64l0-128c0-35.3 28.7-64 64-64l128 0c35.3 0 64 28.7 64 64l0 128zM256 208l0 96c0 8.8 7.2 16 16 16l112 0c8.8 0 16-7.2 16-16l0-96c0-8.8-7.2-16-16-16l-112 0c-8.8 0-16 7.2-16 16z"/></svg>',
        'filter' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M3.9 54.9C10.5 40.9 24.5 32 40 32l432 0c15.5 0 29.5 8.9 36.1 22.9s4.6 30.5-5.2 42.5L320 320.9 320 448c0 12.1-6.8 23.2-17.7 28.6s-23.8 4.3-33.5-3l-64-48c-8.1-6-12.8-15.5-12.8-25.6l0-79.1L9 97.4C-.7 85.4-2.8 68.9 3.9 54.9z"/></svg>',
        'note' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M40 48C26.7 48 16 58.7 16 72l0 48c0 13.3 10.7 24 24 24l0 0 0-72 432 0 0 192c0 13.3 10.7 24 24 24s24-10.7 24-24l0-192c0-13.3-10.7-24-24-24L40 48zM48 376l0 96c0 13.3 10.7 24 24 24l176 0c13.3 0 24-10.7 24-24l0-96c0-13.3-10.7-24-24-24L72 352c-13.3 0-24 10.7-24 24zM320 488c0 13.3 10.7 24 24 24l136 0c13.3 0 24-10.7 24-24l0-80c0-13.3-10.7-24-24-24l-136 0c-13.3 0-24 10.7-24 24l0 80z"/></svg>',
    ];
    
    return $icons[$name] ?? '';
}

/**
 * Action dropdown EXACTLY like cases.php — small icon-only menu.
 * 
 * @param string $viewUrl    URL for view (null=hide)
 * @param string $editUrl    URL for edit (null=hide)
 * @param string $deleteUrl  URL for delete endpoint (null=hide)
 * @param int    $deleteId   ID passed to delete form
 * @param string $extraHtml  Extra items before delete
 */
function action_dropdown($viewUrl = null, $editUrl = null, $deleteUrl = null, $deleteId = 0, $extraHtml = '') {
    $eye   = svg_icon('eye', 'icon-sm');
    $edit  = svg_icon('edit', 'icon-sm');
    $trash = svg_icon('trash', 'icon-sm');

    $html = '<div class="action-dropdown">'
        . '<button class="action-toggle" onclick="(function(btn){document.querySelectorAll(\'.action-menu\').forEach(function(m){m.style.display=\'none\';});var m=btn.nextElementSibling;if(m&&m.classList.contains(\'action-menu\')){m.style.display=\'block\';}event.stopPropagation();})(this)">⋯</button>'
        . '<div class="action-menu" style="display:none; position:absolute; left:0; top:100%; background:#fff; border:1px solid #e5e7eb; border-radius:6px; box-shadow:0 6px 18px rgba(0,0,0,0.08); z-index:999;">';

    if ($viewUrl) {
        $html .= '<a class="action-icon" href="' . htmlspecialchars($viewUrl) . '">' . $eye . '</a>';
    }
    if ($editUrl) {
        $html .= '<a class="action-icon" href="' . htmlspecialchars($editUrl) . '">' . $edit . '</a>';
    }
    if ($extraHtml) {
        $html .= $extraHtml;
    }
    if ($deleteUrl && $deleteId) {
        $html .= '<form method="post" action="' . htmlspecialchars($deleteUrl) . '" style="margin:0;" onsubmit="return confirm(\'آیا مطمئن هستید؟\');">'
            . '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(csrf_token()) . '">'
            . '<input type="hidden" name="id" value="' . (int)$deleteId . '">'
            . '<button type="submit" class="action-icon delete-action">' . $trash . '</button></form>';
    }

    $html .= '</div></div>';

    return $html;
}

/**
 * Action menu toggle + close on outside click (Vanilla JS, no jQuery needed)
 */
function action_menu_script() {
    return '<script>document.addEventListener("click",function(){document.querySelectorAll(".action-menu").forEach(function(m){m.style.display="none";});});</script>';
}
