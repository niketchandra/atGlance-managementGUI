@props(['user', 'size' => 32])
@php
    $avatarSize = (int) $size;
    $avatarName = trim((string) ($user->name ?? '')) ?: (string) ($user->email ?? '');
@endphp
<span {{ $attributes->except('style')->merge(['class' => 'user-avatar']) }}
      role="img"
      aria-label="{{ $avatarName }}"
      title="{{ $avatarName }}"
      style="display:inline-flex; flex-shrink:0; align-items:center; justify-content:center; width:{{ $avatarSize }}px; height:{{ $avatarSize }}px; border-radius:50%; background:#000000; color:#ffffff; font-weight:700; font-size:{{ max(10, (int) round($avatarSize * 0.4)) }}px; line-height:1; letter-spacing:0.02em; user-select:none; {{ $attributes->get('style') }}">{{ \App\Support\UserAvatar::initials($user) }}</span>
