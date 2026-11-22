{{-- Tenant initials badge with a colour derived from the name. $name --}}
@php
    $avatarName = trim((string) $name) ?: '?';
    $avatarPalette = ['#2a78d6', '#6250d6', '#1baf7a', '#eb6834', '#e87ba4', '#0ea5a4', '#d97706'];
    $avatarWords = preg_split('/\s+/u', $avatarName);
    $avatarInitials = mb_strtoupper(mb_substr($avatarWords[0], 0, 1) . (count($avatarWords) > 1 ? mb_substr($avatarWords[count($avatarWords) - 1], 0, 1) : ''));
@endphp
<span class="av" style="background: {{ $avatarPalette[crc32($avatarName) % count($avatarPalette)] }}">{{ $avatarInitials }}</span>
