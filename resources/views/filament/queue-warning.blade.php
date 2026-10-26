{{-- Avís quan hi ha feines a la cua que cap worker no processa. --}}
@if ($warning = \App\Support\QueueStatus::warning())
    <div style="margin-bottom: 1rem; padding: .75rem 1rem; border-radius: .5rem; border: 1px solid #f59e0b; background: rgba(245, 158, 11, .12); font-size: .875rem;">
        <strong>Cua aturada.</strong> {{ $warning }}
    </div>
@endif
