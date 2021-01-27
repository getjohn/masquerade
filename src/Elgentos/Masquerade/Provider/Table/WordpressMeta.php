<?php

namespace Elgentos\Masquerade\Provider\Table;

/**
 * Allows column names to be meta keys, for postmeta, usermeta, commentsmeta, etc
 *
 * The table name should be the base table without the prefix, for the entity - eg. post (or wp_post if you're not using --prefix)
 *
 * Usually we assume the ID field is the 2nd field in the meta table, but if not, provide the ID field as an option, like this:
 *
 * group1:
 *   wp_posts:
 *     provider:
 *       class: \Elgentos\Masquerade\Provider\Table\WordpressMeta
 *       id_field: post_id
 *     columns:
 *       _wp_page_template:
 *         formatter:
 *           name: fixed
 *           value: test-template.php
 *
 */

class WordpressMeta extends Simple
{

    /**
     * @var array of meta keys
     */
    protected $keys = [];
    protected $metaTable;
    protected $metaTablePK;
    protected $metaTableID;

    public function setup()
    {
        // if this table has a meta table: (remove 's' and add 'meta')
        $this->metaTable = preg_replace('/s$/', '', $this->table['name']) . 'meta';

        if ($this->db->getSchemaBuilder()->hasTable($this->metaTable)) {
            $metaColumns = $this->db->getSchemaBuilder()->getColumnListing($this->metaTable);
            $this->metaTablePK = array_shift($metaColumns);
            $this->metaTableID = $this->options['id_field'] ?? array_shift($metaColumns);

            $this->keys = $this->db->table($this->metaTable)->select('meta_key')->distinct()->pluck('meta_key');
        }

        parent::setup();
    }

    protected function _columnExists($name)
    {
        return parent::_columnExists($name) || isset($this->keys[$name]);
    }

    /**
     * @inheritdoc
     */
    public function update($primaryKey, array $updates)
    {

        // first update the columns in the main table
        $staticUpdates = array_filter($updates, function ($value, $code) {
            return $this->_isInBaseTable($code);
        }, ARRAY_FILTER_USE_BOTH);

        // only update main table values if there are any:
        if (count($staticUpdates)) {
            $this->db->table($this->table['name'])->where($this->table['pk'], $primaryKey)->update($staticUpdates);
        }

        // now individually update meta values
        foreach ($updates as $key => $value) {
            if ($this->_isInBaseTable($code)) {
                continue;
            }
            $this->db->table($this->metaTable)
                ->where($this->metaTableID, '=', $primaryKey) // eg. user_id = 3
                ->where('meta_key', '=', $code)
                ->update([
                    'meta_value' => $value
                ]);
        }
    }

    protected function _isInBaseTable($columnName)
    {
        return !isset($this->keys[$columnName]);
    }

    /**
     * @inheritdoc
     */
    public function query() : \Illuminate\Database\Query\Builder
    {
        $query = parent::query();

        $selects = ["{$this->table['name']}.*"];

        // add any required meta values to the query using joins...
        $joinCount = 0;
        foreach ($this->columns() as $columnName => $column) {
            if ($this->_isInBaseTable($columnName)) {
                continue;
            }
            $joinAlias = 'j' . $joinCount;
            $query->leftJoin("{$this->metaTable} as {$joinAlias}", function ($join) use ($joinAlias, $columnName) {
                $join->on("{$this->table['name']}.{$this->table['pk']}", '=', "{$joinAlias}.{$this->metaTableID}")
                    ->where("{$joinAlias}.meta_key", '=', $columnName);
            });
            $selects[] = "{$joinAlias}.meta_value as `{$columnName}`";
        }

        $query->select(...$selects);

        return $query;
    }
}
