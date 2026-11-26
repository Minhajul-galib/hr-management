<?php

namespace App\Http\Controllers;

use App\Models\DesignationCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The designation categories on the Designations page (see DesignationCategory): Merchandising,
 * Machine Operators, General, ... Each is its own ladder of designations with its own serials.
 * They can be added, renamed and deleted while no designation belongs to them. They follow the
 * designations permissions: store = create, update = edit, destroy = delete.
 */
class DesignationCategoryController extends Controller
{
    /**
     * Every category with how many designations it holds, for the page.
     *
     * @return list<array<string, mixed>>
     */
    public static function overview(): array
    {
        return DesignationCategory::withCount('designations')->orderBy('id')->get()
            ->map(fn (DesignationCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'color' => $category->color(),
                'designations_count' => $category->designations_count,
            ])->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $name = $this->name($request, null);

        DesignationCategory::create(['name' => $name]);

        return redirect()->route('designations.index')->with('success', "Category \"{$name}\" added. Designations can now be put in it.");
    }

    public function update(Request $request, DesignationCategory $category): RedirectResponse
    {
        $name = $this->name($request, $category->id);

        $category->update(['name' => $name]);

        return redirect()->route('designations.index')->with('success', "Category \"{$name}\" updated.");
    }

    public function destroy(DesignationCategory $category): RedirectResponse
    {
        $count = $category->designations()->withTrashed()->count(); // a designation in the Trash still belongs to it
        if ($count > 0) {
            $inTrash = $count - $category->designations()->count();
            $note = $inTrash > 0 ? " ({$inTrash} of them in the Trash)" : '';

            return redirect()->route('designations.index')->with('error', "The category \"{$category->name}\" cannot be deleted because it holds {$count} designation(s){$note}. Move them to another category first.");
        }

        $category->delete();

        return redirect()->route('designations.index')->with('success', "Category \"{$category->name}\" deleted.");
    }

    /** The category's name: needed, and not another category's (ignoring case). */
    private function name(Request $request, ?int $ignore): string
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                function (string $attribute, mixed $value, \Closure $fail) use ($ignore) {
                    $taken = is_string($value) && DesignationCategory::query()
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)))])
                        ->when($ignore, fn ($q) => $q->whereKeyNot($ignore))
                        ->exists();
                    if ($taken) {
                        $fail('Another category already has this name.');
                    }
                },
            ],
        ], [
            'name.required' => 'Give the category a name, e.g. Merchandising.',
        ]);

        return trim(preg_replace('/\s+/u', ' ', $data['name']));
    }
}
