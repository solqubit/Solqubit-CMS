<?php

namespace Tests\Unit;

use App\Services\SearchText;
use PHPUnit\Framework\TestCase;

class SearchTextTest extends TestCase
{
    public function test_search_text_keeps_readable_text_without_markup_block_names_or_accents(): void
    {
        $text = (new SearchText)->forPost('Canción ÚNICA', null, [
            ['type' => 'heading', 'data' => ['text' => 'Año nuevo', 'level' => 'h2']],
            ['type' => 'paragraph', 'data' => ['html' => '<p>Uno &amp; <em>dos</em></p><p>tres</p>']],
            ['type' => 'table', 'data' => ['columns' => ['Nombre'], 'rows' => [['cells' => 'Ñandú']]]],
            ['type' => 'code', 'data' => ['text' => 'echo <b>', 'label' => 'Bash', 'language' => 'bash']],
            ['type' => 'divider', 'data' => []],
            ['type' => 'video', 'data' => ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'caption' => 'Demostración']],
        ]);

        $this->assertSame('cancion unica ano nuevo uno & dos tres nombre nandu bash echo <b> demostracion', $text);
        $this->assertSame('arbol', (new SearchText)->normalize('  ÁRBOL '));
    }
}
