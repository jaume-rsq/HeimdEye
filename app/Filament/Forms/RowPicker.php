<?php

namespace App\Filament\Forms;

use Closure;
use Filament\Forms\Components\CheckboxList;

/**
 * Selecció múltiple en forma de taula amb scroll: una fila per element, amb columnes
 * d'informació, cerca i marcar/desmarcar els visibles. Escala millor que una graella
 * de caselles quan hi ha molts alumnes o equips.
 */
class RowPicker extends CheckboxList
{
    protected string $view = 'filament.forms.row-picker';

    /** @var list<string> | Closure */
    protected array|Closure $headers = [];

    /** @var array<string, list<string|null>> | Closure */
    protected array|Closure $rows = [];

    /** @param  list<string> | Closure  $headers */
    public function headers(array|Closure $headers): static
    {
        $this->headers = $headers;

        return $this;
    }

    /**
     * Files indexades pel valor que es desa; la primera cel·la fa d'etiqueta.
     *
     * @param  array<string|int, list<string|null>> | Closure  $rows
     */
    public function rows(array|Closure $rows): static
    {
        $this->rows = $rows;

        return $this;
    }

    /** @return list<string> */
    public function getHeaders(): array
    {
        return $this->evaluate($this->headers);
    }

    /** @return array<string, list<string|null>> */
    public function getRows(): array
    {
        return $this->evaluate($this->rows);
    }

    public function getOptions(): array
    {
        return array_map(fn (array $cells) => (string) ($cells[0] ?? ''), $this->getRows());
    }
}
