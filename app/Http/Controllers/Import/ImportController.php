<?php

namespace App\Http\Controllers\Import;

use App\Domain\Import\ImportContext;
use App\Domain\Import\Importer;
use App\Domain\Import\ImportService;
use App\Domain\Import\TemplateBuilder;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Excel templates and imports. Each import type is guarded by the permission of the screen it belongs to. */
class ImportController extends Controller
{
    public function __construct(private ImportService $imports, private OutletContext $outlet) {}

    public function info(Request $request, string $type): JsonResponse
    {
        $importer = $this->importer($request, $type);

        return response()->json(['title' => $importer->title(), 'extra' => $importer->extraFields(), 'notes' => $importer->notes()]);
    }

    public function template(Request $request, string $type): StreamedResponse
    {
        $importer = $this->importer($request, $type);

        return TemplateBuilder::download(
            $importer->filename(), $importer->columns(), $importer->templateRows($this->outlet->id()), $importer->notes(), $importer->lists($this->outlet->id()),
        );
    }

    public function preview(Request $request, string $type): JsonResponse
    {
        $importer = $this->importer($request, $type);
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv,txt|max:4096']);

        return response()->json($this->imports->preview($importer, $request->file('file')->getRealPath(), $this->context($request, $importer, [])));
    }

    public function apply(Request $request, string $type): JsonResponse
    {
        $importer = $this->importer($request, $type);
        $data = $request->validate([
            'rows' => 'required|array|min:1|max:2000', 'rows.*.line' => 'required|integer', 'rows.*.cells' => 'required|array', 'extra' => 'nullable|array',
        ]);
        $ctx = $this->context($request, $importer, $data['extra'] ?? []);

        return response()->json(['message' => $this->imports->apply($importer, $data['rows'], $ctx)]);
    }

    private function importer(Request $request, string $type): Importer
    {
        $importer = $this->imports->find($type);
        abort_unless($this->actor($request)->can($importer->permission()), 403);

        return $importer;
    }

    /** @param  array<string, mixed>  $extra */
    private function context(Request $request, Importer $importer, array $extra): ImportContext
    {
        $allowed = array_column($importer->extraFields(), 'key');

        return new ImportContext($this->outlet->id(), $this->actor($request), array_intersect_key($extra, array_flip($allowed)));
    }
}
