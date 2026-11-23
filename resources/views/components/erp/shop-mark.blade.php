@php
    $name = (string) config('erp.company.name');
    $logo = (string) config('erp.company.logo_path');
    $initials = collect(preg_split('/\s+/', trim($name)))->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');
@endphp
<span class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-lg {{ $logo ? 'bg-white p-1' : 'bg-indigo-600' }} text-sm font-bold text-white">
    @if ($logo)<img src="{{ route('branding.logo', ['v' => substr(hash('sha256', $logo), 0, 12)]) }}" alt="{{ $name }} logo" class="h-full w-full object-contain">@else{{ $initials }}@endif
</span>
