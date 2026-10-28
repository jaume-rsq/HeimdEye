{{-- Icones de public/. El ?v= canvia quan es canvia el fitxer, perquè el navegador no es quedi amb l'antiga. --}}
@php($v = fn (string $file) => '/'.$file.'?v='.(@filemtime(public_path($file)) ?: 1))
<link rel="icon" type="image/x-icon" href="{{ $v('favicon.ico') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ $v('favicon-32x32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ $v('favicon-16x16.png') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ $v('apple-touch-icon.png') }}">
