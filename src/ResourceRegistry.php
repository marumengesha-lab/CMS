<?php
/**
 * Declarative registry of the business / academic entities.
 *
 * Every module page (members.php, finance.php, students.php, ...) is backed
 * by one entry here. The entry describes the table, the permission prefix,
 * the editable fields and which columns carry the institution / department.
 * ResourceController turns that description into full, scope-aware CRUD.
 *
 * Field descriptor keys:
 *   label     human label
 *   type      text | textarea | number | decimal | date | select | ref | checkbox | email | tel
 *   required  bool
 *   options   array of allowed values (select)
 *   ref       ['table' => 'levels', 'label' => 'name'] for a foreign key
 *   hide      true = never shown in the table
 */
declare(strict_types=1);

final class ResourceRegistry
{
    /** @var array<string,array>|null */
    private static ?array $cache = null;

    /**
     * Setup entities that are governed by ONE permission instead of the
     * usual view/create/update/delete quartet. The value is the suffix
     * appended to the resource permission prefix, e.g.
     *   academic_years      -> academics.year.manage
     *   teacher_assignments -> academics.teacher.assign
     */
    private const MANAGE_SUFFIX = [
        'academic_years'       => 'manage',
        'levels'               => 'manage',
        'classrooms'           => 'manage',
        'courses'              => 'manage',
        'academic_departments' => 'manage',
        'assessments'          => 'manage',
        'grades'               => 'manage',
        'certificates'         => 'manage',
        'teacher_assignments'  => 'assign',
    ];

    public static function all(): array
    {
        return self::$cache ??= require BASE_PATH . '/config/resources.php';
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** @throws HttpError 404 when the resource is unknown */
    public static function get(string $key): array
    {
        $all = self::all();
        if (!isset($all[$key])) {
            throw new HttpError(404, "Unknown module resource: {$key}");
        }
        $def = $all[$key];
        $def['key'] = $key;

        // Defaults so every definition stays terse.
        $def += [
            'table'      => $key,
            'singular'   => ucfirst($key),
            'plural'     => ucfirst($key),
            'permission' => $key,
            'title'      => 'name',
            'search'     => [],
            'order'      => 'id',
            'order_dir'  => 'DESC',
            'scope_map'  => ['institution' => 'institution_id', 'department' => 'department_id'],
            'page_size'  => 25,
        ];

        if (isset(self::MANAGE_SUFFIX[$key])) {
            $def['manage_suffix'] = self::MANAGE_SUFFIX[$key];
        }
        return $def;
    }

    /** Keys grouped for the navigation menu. */
    public static function grouped(): array
    {
        $groups = ['modules' => [], 'academics' => []];
        foreach (self::all() as $key => $def) {
            $group = ($def['group'] ?? 'modules') === 'academics' ? 'academics' : 'modules';
            $groups[$group][$key] = $def;
        }
        return $groups;
    }
}
