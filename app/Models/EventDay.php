<?php

namespace App\Models;

use App\Exceptions\EventDayException;
use Database\Factories\EventDayFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\UniqueConstraintViolationException;

class EventDay extends Model
{
    /** @use HasFactory<EventDayFactory> */
    use HasFactory;

    protected $fillable = ['date', 'label'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Human-friendly name, e.g. "28/08/2026" or "28/08/2026 (Venerdì)".
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(
            fn (): string => $this->date->format('d/m/Y').($this->label ? " ({$this->label})" : ''),
        );
    }

    public function foods(): BelongsToMany
    {
        return $this->belongsToMany(Food::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function scopeOpen($query)
    {
        return $query->whereNotNull('opened_at')->whereNull('closed_at');
    }

    public function isOpen(): bool
    {
        return $this->opened_at !== null && $this->closed_at === null;
    }

    /**
     * The operational day currently open, if any.
     */
    public static function current(): ?self
    {
        return static::query()->open()->first();
    }

    /**
     * Open this operational day. Only one day may be open at a time.
     *
     * @throws EventDayException
     */
    public function open(User $operator): void
    {
        if ($this->closed_at !== null) {
            throw new EventDayException('This operational day has already been closed.');
        }

        if ($this->opened_at !== null) {
            throw new EventDayException('This operational day is already open.');
        }

        if (static::current() !== null) {
            throw new EventDayException('Another operational day is already open. Close it first.');
        }

        try {
            $this->forceFill([
                'opened_at' => now(),
                'opened_by' => $operator->id,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // Lost a race: another operator opened a day between our check and save.
            throw new EventDayException('Another operational day is already open. Close it first.');
        }
    }

    /**
     * Close this operational day.
     *
     * @throws EventDayException
     */
    public function close(User $operator): void
    {
        if (! $this->isOpen()) {
            throw new EventDayException('This operational day is not open.');
        }

        $this->forceFill([
            'closed_at' => now(),
            'closed_by' => $operator->id,
        ])->save();
    }
}
