<?php

use Saola\Compiler\Directive\Sao;

// Nạp bởi saola/compiler (extra.saola.directives) lúc build view `.sao`.
return [
    // Có ở cả hai phía: helpers.php (Blade) và dist/client.js (App.Helper).
    Sao::helper('canRoute'),

    // <a #permission="'admin.roles.index'">…</a> — thẻ nào cũng được: giá trị là
    // TÊN ROUTE, không đọc href. Chỉ ẩn giao diện; middleware vẫn chặn thật.
    Sao::tag('permission', fn (string $route) => ["@if(canRoute({$route}))", '@endif']),
];
