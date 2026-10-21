<?php

namespace App\Forms\Components;

use App\Services\EditorBlocks;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Js;

class ContentEditor extends RichEditor
{
    private string $editorContext = 'post';

    private bool $hasDrafts = true;

    /** Several editors on one form would share a single draft slot, so they opt out of autosaved drafts. */
    public function withoutDrafts(): static
    {
        $this->hasDrafts = false;

        return $this;
    }

    public function editorContext(string $context): static
    {
        $this->editorContext = $context;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $tools = [];
        foreach (EditorBlocks::all() as $type => $block) {
            $tool = RichEditorTool::make('sq_'.$type)->label($block::label())->hiddenLabel(false)->icon($block::icon());
            if ($type !== 'image') {
                // Still rendered so the insert menu can trigger it, but kept out of the toolbar.
                $tool->extraAttributes(['class' => 'sq-hidden-tool', 'tabindex' => '-1', 'aria-hidden' => 'true']);
            }
            $config = $block::defaults();
            if ($type === 'table') {
                $tool->jsHandler('$getEditor()?.chain().focus().insertTable({ rows: 3, cols: 2, withHeaderRow: true }).run()');
            } elseif ($config !== null) {
                $node = Js::from([['type' => 'customBlock', 'attrs' => ['id' => $type, 'config' => $config, 'preview' => '']], ['type' => 'paragraph']])->toHtml();
                $tool->jsHandler('$getEditor()?.chain().focus().insertContent('.$node.').run()');
            } else {
                $tool->action('customBlock', "{ id: '".$type."', mode: 'insert' }");
            }
            $tools[] = $tool;
        }
        $tools[] = RichEditorTool::make('sq_insert')->label('Insertar')->hiddenLabel(false)->icon('heroicon-o-plus-circle')->jsHandler('$getEditor()?.commands.solqubitInsert()');
        $tools[] = RichEditorTool::make('sq_focus')->label('Enfoque')->hiddenLabel(false)->icon('heroicon-o-arrows-pointing-out')->jsHandler('$getEditor()?.commands.solqubitFocus()');
        $tools[] = RichEditorTool::make('sq_paragraph')->label('Párrafo')->hiddenLabel(false)->icon('heroicon-o-bars-3-bottom-left')->jsHandler('$getEditor()?.chain().focus().setParagraph().run()');
        $this->json()->preventFileAttachmentPathTampering(false)->tools($tools)->customBlocks(array_values(EditorBlocks::all()))->minimalCustomBlockControls()
            ->plugins([new EditorPlugin])
            ->toolbarButtons([
                ['sq_paragraph', 'h2', 'h3', 'bold', 'italic', 'underline', 'strike', 'code', 'link', 'bulletList', 'orderedList', 'blockquote', 'horizontalRule', 'undo', 'redo'],
                ['sq_focus'],
                ['sq_image', 'sq_insert', 'sq_video', 'sq_media_video', 'sq_file', 'sq_code', 'sq_table', 'sq_notice', 'sq_details', 'sq_list', 'sq_links'],
            ])->floatingToolbars(['paragraph' => ['bold', 'italic', 'link'], 'heading' => ['bold', 'italic', 'link']])->linkProtocols(['http', 'https', 'mailto'])->stickyToolbar()->stickyOffset('76px')
            ->minHeight('480px')->placeholder('Empieza a escribir… Usa / o «Insertar» para añadir un elemento.')
            ->helperText('Escribe directamente o pega texto con formato. Usa / para insertar; selecciona texto para darle formato.')
            ->extraAttributes(fn (?Model $record): array => ['class' => 'solqubit-editor', 'data-editor-context' => $this->editorContext, 'data-record-id' => $record?->getKey() ?? '', 'data-commands' => base64_encode(json_encode(EditorBlocks::commands(), JSON_UNESCAPED_UNICODE))] + ($this->hasDrafts ? ['data-draft-url' => route('editor.draft')] : []))
            ->default([])->columnSpanFull();
    }

    public function getDefaultStateCasts(): array
    {
        return [new EditorStateCast($this)];
    }

    public function saveFileAttachments(): void
    {
        // Files are managed by MediaLibrary and ContentSchema, never by rich-editor paths.
        $this->rawState((new EditorStateCast($this))->set($this->getRawState()));
    }
}
