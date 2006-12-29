<?php
/*
 *  $Id$
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.phpdoctrine.com>.
 */
/**
 * Doctrine_Table   represents a database table
 *                  each Doctrine_Table holds the information of foreignKeys and associations
 *
 *
 * @author      Konsta Vesterinen <kvesteri@cc.hut.fi>
 * @package     Doctrine
 * @license     http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @version     $Revision$
 * @category    Object Relational Mapping
 * @link        www.phpdoctrine.com
 * @since       1.0
 */
class Doctrine_Table extends Doctrine_Configurable implements Countable {
    /**
     * @var array $data                                 temporary data which is then loaded into Doctrine_Record::$data
     */
    private $data             = array();
    /**
     * @var array $relations                            an array containing all the Doctrine_Relation objects for this table
     */
    private $relations        = array();
    /**
     * @var array $primaryKeys                          an array containing all primary key column names
     */
    private $primaryKeys      = array();
    /**
     * @var mixed $identifier
     */
    private $identifier;
    /**
     * @see Doctrine_Identifier constants
     * @var integer $identifierType                     the type of identifier this table uses
     */
    private $identifierType;
    /**
     * @var string $query                               cached simple query
     */
    private $query;
    /**
     * @var Doctrine_Connection $conn                   Doctrine_Connection object that created this table
     */
    private $conn;
    /**
     * @var string $name
     */
    private $name;
    /**
     * @var array $identityMap                          first level cache
     */
    private $identityMap        = array();
    /**
     * @var Doctrine_Table_Repository $repository       record repository
     */
    private $repository;
    /**
     * @var array $columns                  an array of column definitions,
     *                                      keys as column names and values as column definitions
     *
     *                                      the value array has three values:
     *
     *                                      the column type, eg. 'integer'
     *                                      the column length, eg. 11
     *                                      the column options/constraints/validators. eg array('notnull' => true)
     *
     *                                      so the full columns array might look something like the following:
     *                                      array(
     *                                             'name' => array('string',  20, array('notnull' => true, 'default' => 'someone')),
     *                                             'age'  => array('integer', 11, array('notnull' => true))
     *                                              )
     */
    protected $columns          = array();
    /**
     * @var array $bound                                bound relations
     */
    private $bound              = array();
    /**
     * @var array $boundAliases                         bound relation aliases
     */
    private $boundAliases       = array();
    /**
     * @var integer $columnCount                        cached column count, Doctrine_Record uses this column count in when
     *                                                  determining its state
     */
    private $columnCount;
    /**
     * @var array $parents                              the parent classes of this component
     */
    private $parents            = array();
    /**
     * @var boolean $hasDefaultValues                   whether or not this table has default values
     */
    private $hasDefaultValues;
    /**
     * @var array $options                  an array containing all options
     *
     *      -- name                         name of the component, for example component name of the GroupTable is 'Group'
     *
     *      -- tableName                    database table name, in most cases this is the same as component name but in some cases
     *                                      where one-table-multi-class inheritance is used this will be the name of the inherited table
     *
     *      -- sequenceName                 Some databases need sequences instead of auto incrementation primary keys,
     *                                      you can set specific sequence for your table by calling setOption('sequenceName', $seqName)
     *                                      where $seqName is the name of the desired sequence
     *
     *      -- enumMap                      enum value arrays
     *
     *      -- inheritanceMap               inheritanceMap is used for inheritance mapping, keys representing columns and values
     *                                      the column values that should correspond to child classes
     */
    protected $options          = array('name'           => null,
                                        'tableName'      => null,
                                        'sequenceName'   => null,
                                        'inheritanceMap' => array(),
                                        'enumMap'        => array(),
                                        );

    /**
     * the constructor
     * @throws Doctrine_Connection_Exception    if there are no opened connections
     * @throws Doctrine_Table_Exception         if there is already an instance of this table
     * @return void
     */
    public function __construct($name, Doctrine_Connection $conn) {
        $this->conn = $conn;

        $this->setParent($this->conn);

        $this->options['name'] = $name;

        if ( ! class_exists($name) || empty($name)) {
            throw new Doctrine_Exception("Couldn't find class $name");
        }
        $record = new $name($this);

        $names = array();

        $class = $name;

        // get parent classes

        do {
            if ($class == "Doctrine_Record")
                break;

            $name  = $class;
            $names[] = $name;
        } while ($class = get_parent_class($class));

        // reverse names
        $names = array_reverse($names);

        // create database table
        if (method_exists($record, 'setTableDefinition')) {
            $record->setTableDefinition();

            $this->columnCount = count($this->columns);

            if (isset($this->columns)) {
                // get the declaring class of setTableDefinition method
                $method    = new ReflectionMethod($this->options['name'], 'setTableDefinition');
                $class     = $method->getDeclaringClass();

                if ( ! isset($this->options['tableName'])) {
                    $this->options['tableName'] = Doctrine::tableize($class->getName());
                }
                switch (count($this->primaryKeys)) {
                case 0:
                    $this->columns = array_merge(array('id' =>
                                                    array('integer',
                                                          20,
                                                          array('autoincrement' => true,
                                                                'primary'       => true
                                                                )
                                                          )
                                                    ), $this->columns);

                    $this->primaryKeys[] = 'id';
                    $this->identifier = 'id';
                    $this->identifierType = Doctrine_Identifier::AUTO_INCREMENT;
                    $this->columnCount++;
                    break;
                default:
                    if (count($this->primaryKeys) > 1) {
                        $this->identifier = $this->primaryKeys;
                        $this->identifierType = Doctrine_Identifier::COMPOSITE;

                    } else {
                        foreach ($this->primaryKeys as $pk) {
                            $e = $this->columns[$pk][2];

                            $found = false;

                            foreach ($e as $option => $value) {
                                if ($found)
                                    break;

                                $e2 = explode(":",$option);

                                switch (strtolower($e2[0])) {
                                case "autoincrement":
                                    $this->identifierType = Doctrine_Identifier::AUTO_INCREMENT;
                                    $found = true;
                                    break;
                                case "seq":
                                    $this->identifierType = Doctrine_Identifier::SEQUENCE;
                                    $found = true;
                                    break;
                                };
                            }
                            if ( ! isset($this->identifierType)) {
                                $this->identifierType = Doctrine_Identifier::NORMAL;
                            }
                            $this->identifier = $pk;
                        }
                    }
                };

                if ($this->getAttribute(Doctrine::ATTR_CREATE_TABLES)) {
                    if (Doctrine::isValidClassname($class->getName())) {
                        try {
                            $columns = array();
                            foreach ($this->columns as $name => $column) {
                                $definition = $column[2];
                                $definition['type'] = $column[0];
                                $definition['length'] = $column[1];

                                if ($definition['type'] == 'enum' && isset($definition['default'])) {
                                    $definition['default'] = $this->enumIndex($name, $definition['default']);
                                }
                                if ($definition['type'] == 'boolean' && isset($definition['default'])) {
                                    $definition['default'] = (int) $definition['default'];
                                }
                                $columns[$name] = $definition;
                            }

                            $this->conn->export->createTable($this->options['tableName'], $columns);
                        } catch(Exception $e) {

                        }
                    }
                }

            }
        } else {
            throw new Doctrine_Table_Exception("Class '$name' has no table definition.");
        }

        $record->setUp();

        // save parents
        array_pop($names);
        $this->parents   = $names;

        $this->query     = "SELECT ".implode(", ",array_keys($this->columns))." FROM ".$this->getTableName();

        // check if an instance of this table is already initialized
        if ( ! $this->conn->addTable($this)) {
            throw new Doctrine_Table_Exception();
        }
        $this->repository = new Doctrine_Table_Repository($this);
    }
    /**
     * createQuery
     * creates a new Doctrine_Query object and adds the component name
     * of this table as the query 'from' part
     *
     * @return Doctrine_Query
     */
    public function createQuery() {
        return Doctrine_Query::create()->from($this->getComponentName());
    }
    /**
     * getRepository
     *
     * @return Doctrine_Table_Repository
     */
    public function getRepository() {
        return $this->repository;
    }

    public function setOption($name, $value) {
        switch ($name) {
        case 'name':
        case 'tableName':
            break;
        case 'enumMap':
        case 'inheritanceMap':
            if ( ! is_array($value)) {
                throw new Doctrine_Table_Exception($name.' should be an array.');
            }
            break;
        }
        $this->options[$name] = $value;
    }

    public function usesInheritanceMap() {
        return ( ! empty($this->options['inheritanceMap']));
    }
    public function getOption($name) {
        if (isset($this->options[$name])) {
            return $this->options[$name];
        }
        return null;
    }
    /**
     * setColumn
     * @param string $name
     * @param string $type
     * @param integer $length
     * @param mixed $options
     * @return void
     */
    final public function setColumn($name, $type, $length = null, $options = array()) {
        if (is_string($options)) {
            $options = explode('|', $options);
        }
        foreach ($options as $k => $option) {
            if (is_numeric($k)) {
                if ( ! empty($option)) {
                    $options[$option] = true;
                }
                unset($options[$k]);
            }
        }
        $name = strtolower($name);

        if ($length == null)
            $length = 2147483647;

        $this->columns[$name] = array($type, $length, $options);

        if (isset($options['primary'])) {
            $this->primaryKeys[] = $name;
        }
        if (isset($options['default'])) {
            $this->hasDefaultValues = true;
        }
    }
    /**
     * hasDefaultValues
     * returns true if this table has default values, otherwise false
     *
     * @return boolean
     */
    public function hasDefaultValues() {
        return $this->hasDefaultValues;
    }
    /**
     * getDefaultValueOf
     * returns the default value(if any) for given column
     *
     * @param string $column
     * @return mixed
     */
    public function getDefaultValueOf($column) {
        $column = strtolower($column);
        if ( ! isset($this->columns[$column])) {
            throw new Doctrine_Table_Exception("Couldn't get default value. Column ".$column." doesn't exist.");
        }
        if (isset($this->columns[$column][2]['default'])) {
            return $this->columns[$column][2]['default'];
        } else {
            return null;
        }
    }
    /**
     * @return mixed
     */
    final public function getIdentifier() {
        return $this->identifier;
    }
    /**
     * @return integer
     */
    final public function getIdentifierType() {
        return $this->identifierType;
    }
    /**
     * hasColumn
     * @return boolean
     */
    final public function hasColumn($name) {
        return isset($this->columns[$name]);
    }
    /**
     * @param mixed $key
     * @return void
     */
    final public function setPrimaryKey($key) {
        switch (gettype($key)) {
        case "array":
            $this->primaryKeys = array_values($key);
            break;
        case "string":
            $this->primaryKeys[] = $key;
            break;
        };
    }
    /**
     * returns all primary keys
     * @return array
     */
    final public function getPrimaryKeys() {
        return $this->primaryKeys;
    }
    /**
     * @return boolean
     */
    final public function hasPrimaryKey($key) {
        return in_array($key,$this->primaryKeys);
    }
    /**
     * @param $sequence
     * @return void
     */
    final public function setSequenceName($sequence) {
        $this->options['sequenceName'] = $sequence;
    }
    /**
     * @return string   sequence name
     */
    final public function getSequenceName() {
        return $this->options['sequenceName'];
    }
    /**
     * getParents
     */
    final public function getParents() {
        return $this->parents;
    }
    /**
     * @return boolean
     */
    final public function hasInheritanceMap() {
        return (empty($this->options['inheritanceMap']));
    }
    /**
     * @return array        inheritance map (array keys as fields)
     */
    final public function getInheritanceMap() {
        return $this->options['inheritanceMap'];
    }
    /**
     * return all composite paths in the form [component1].[component2]. . .[componentN]
     * @return array
     */
    final public function getCompositePaths() {
        $array = array();
        $name  = $this->getComponentName();
        foreach ($this->bound as $k=>$a) {
            try {
            $fk = $this->getRelation($k);
            switch ($fk->getType()) {
            case Doctrine_Relation::ONE_COMPOSITE:
            case Doctrine_Relation::MANY_COMPOSITE:
                $n = $fk->getTable()->getComponentName();
                $array[] = $name.".".$n;
                $e = $fk->getTable()->getCompositePaths();
                if ( ! empty($e)) {
                    foreach ($e as $name) {
                        $array[] = $name.".".$n.".".$name;
                    }
                }
                break;
            };
            } catch(Doctrine_Table_Exception $e) {

            }
        }
        return $array;
    }
    /**
     * returns all bound relations
     *
     * @return array
     */
    public function getBounds() {
        return $this->bound;
    }
    /**
     * returns a bound relation array
     *
     * @param string $name
     * @return array
     */
    public function getBound($name) {
        if ( ! isset($this->bound[$name])) {
            throw new Doctrine_Table_Exception('Unknown bound '.$name);
        }
        return $this->bound[$name];
    }
    /**
     * returns a bound relation array
     *
     * @param string $name
     * @return array
     */
    public function getBoundForName($name, $component) {

        foreach ($this->bound as $k => $bound) {
            $e = explode('.', $bound[0]);

            if ($bound[3] == $name && $e[0] == $component) {
                return $this->bound[$k];
            }
        }
        throw new Doctrine_Table_Exception('Unknown bound '.$name);
    }
    /**
     * returns the alias for given component name
     *
     * @param string $name
     * @return string
     */
    public function getAlias($name) {
        if (isset($this->boundAliases[$name])) {
            return $this->boundAliases[$name];
        }
        return $name;
    }
    /**
     * returns component name for given alias
     *
     * @param string $alias
     * @return string
     */
    public function getAliasName($alias) {
        if ($name = array_search($alias, $this->boundAliases)) {
            return $name;
        }
        return $alias;
    }
    /**
     * unbinds all relations
     *
     * @return void
     */
    public function unbindAll() {
        $this->bound        = array();
        $this->relations    = array();
        $this->boundAliases = array();
    }
    /**
     * unbinds a relation
     * returns true on success, false on failure
     *
     * @param $name
     * @return boolean
     */
    public function unbind($name) {
        if ( ! isset($this->bound[$name])) {
            return false;
        }
        unset($this->bound[$name]);

        if (isset($this->relations[$name])) {
            unset($this->relations[$name]);
        }
        if (isset($this->boundAliases[$name])) {
            unset($this->boundAliases[$name]);
        }
        return true;
    }
    /**
     * binds a relation
     *
     * @param string $name
     * @param string $field
     * @return void
     */
    public function bind($name, $field, $type, $localKey) {
        if (isset($this->relations[$name])) {
            unset($this->relations[$name]);
        }
        $e          = explode(" as ",$name);
        $name       = $e[0];

        if (isset($e[1])) {
            $alias = $e[1];
            $this->boundAliases[$name] = $alias;
        } else {
            $alias = $name;
        }

        $this->bound[$alias] = array($field, $type, $localKey, $name);
    }
    /**
     * getComponentName
     * @return string                   the component name
     */
    public function getComponentName() {
        return $this->options['name'];
    }
    /**
     * @return Doctrine_Connection
     */
    public function getConnection() {
        return $this->conn;
    }
    /**
     * hasRelatedComponent
     * @return boolean
     */
    final public function hasRelatedComponent($name, $component) {
         return (strpos($this->bound[$name][0], $component.'.') !== false);
    }
    /**
     * @param string $name              component name of which a foreign key object is bound
     * @return boolean
     */
    final public function hasRelation($name) {
        if (isset($this->bound[$name])) {
            return true;
        }
        foreach ($this->bound as $k=>$v) {
            if ($this->hasRelatedComponent($k, $name)) {
                return true;
            }
        }
        return false;
    }
    /**
     * getRelation
     *
     * @param string $name              component name of which a foreign key object is bound
     * @return Doctrine_Relation
     */
    final public function getRelation($name, $recursive = true) {
        $original = $name;

        if (isset($this->relations[$name])) {
            return $this->relations[$name];
        }
        if (isset($this->bound[$name])) {
            $type       = $this->bound[$name][1];
            $local      = $this->bound[$name][2];
            list($component, $foreign) = explode(".",$this->bound[$name][0]);
            $alias      = $name;
            $name       = $this->bound[$alias][3];

            $table      = $this->conn->getTable($name);

            if ($component == $this->options['name'] || in_array($component, $this->parents)) {
                // ONE-TO-ONE
                if ($type == Doctrine_Relation::ONE_COMPOSITE ||
                   $type == Doctrine_Relation::ONE_AGGREGATE) {
                    if ( ! isset($local)) {
                        $local = $table->getIdentifier();
                    }
                    $relation = new Doctrine_Relation_LocalKey($table, $foreign, $local, $type, $alias);
                } else {
                    $relation = new Doctrine_Relation_ForeignKey($table, $foreign, $local, $type, $alias);
                }

            } elseif ($component == $name ||
                    ($component == $alias)) {     //  && ($name == $this->options['name'] || in_array($name,$this->parents))

                if ( ! isset($local)) {
                    $local = $this->identifier;
                }
                // ONE-TO-MANY or ONE-TO-ONE
                $relation = new Doctrine_Relation_ForeignKey($table, $local, $foreign, $type, $alias);

            } else {
                // MANY-TO-MANY
                // only aggregate relations allowed

                if ($type != Doctrine_Relation::MANY_AGGREGATE)
                    throw new Doctrine_Table_Exception("Only aggregate relations are allowed for many-to-many relations");

                $classes = array_merge($this->parents, array($this->options['name']));

                foreach (array_reverse($classes) as $class) {
                    try {
                        $bound = $table->getBoundForName($class, $component);
                        break;
                    } catch(Doctrine_Table_Exception $exc) { }
                }
                if ( ! isset($bound)) {
                    throw new Doctrine_Table_Exception("Couldn't map many-to-many relation for "
                                                      . $this->options['name'] . " and $name. Components use different join tables.");
                }
                if ( ! isset($local)) {
                    $local = $this->identifier;
                }
                $e2     = explode('.', $bound[0]);
                $fields = explode('-', $e2[1]);

                if ($e2[0] != $component)
                    throw new Doctrine_Table_Exception($e2[0] . ' doesn\'t match ' . $component);

                $associationTable = $this->conn->getTable($e2[0]);

                if (count($fields) > 1) {
                    // SELF-REFERENCING THROUGH JOIN TABLE
                    $this->relations[$e2[0]] = new Doctrine_Relation_ForeignKey($associationTable, $local, $fields[0],Doctrine_Relation::MANY_COMPOSITE, $e2[0]);

                    $relation = new Doctrine_Relation_Association_Self($table, $associationTable, $fields[0], $fields[1], $type, $alias);
                } else {

                    // auto initialize a new one-to-one relationship for association table
                    $associationTable->bind($this->getComponentName(),  $associationTable->getComponentName(). '.' .$e2[1], Doctrine_Relation::ONE_AGGREGATE, $this->getIdentifier());
                    $associationTable->bind($table->getComponentName(), $associationTable->getComponentName(). '.' .$foreign, Doctrine_Relation::ONE_AGGREGATE, $table->getIdentifier());

                    // NORMAL MANY-TO-MANY RELATIONSHIP
                    $this->relations[$e2[0]] = new Doctrine_Relation_ForeignKey($associationTable, $local, $e2[1], Doctrine_Relation::MANY_COMPOSITE, $e2[0]);

                    $relation = new Doctrine_Relation_Association($table, $associationTable, $e2[1], $foreign, $type, $alias);
                }

            }

            $this->relations[$alias] = $relation;
            return $this->relations[$alias];
        }

        // load all relations
        $this->getRelations();

        if ($recursive) {
            return $this->getRelation($original, false);
        } else {
            throw new Doctrine_Table_Exception($this->options['name'] . " doesn't have a relation to " . $original);
        }
    }
    /**
     * returns an array containing all foreign key objects
     *
     * @return array
     */
    final public function getRelations() {
        $a = array();
        foreach ($this->bound as $k=>$v) {
            $this->getRelation($k);
        }

        return $this->relations;
    }
    /**
     * sets the database table name
     *
     * @param string $name              database table name
     * @return void
     */
    final public function setTableName($name) {
        $this->options['tableName'] = $name;
    }

    /**
     * returns the database table name
     *
     * @return string
     */
    final public function getTableName() {
        return $this->options['tableName'];
    }
    /**
     * create
     * creates a new record
     *
     * @param $array                    an array where keys are field names and values representing field values
     * @return Doctrine_Record
     */
    public function create(array $array = array()) {
        $this->data         = $array;
        $record = new $this->options['name']($this, true);
        $this->data         = array();
        return $record;
    }
    /**
     * finds a record by its identifier
     *
     * @param $id                       database row id
     * @return Doctrine_Record|false    a record for given database identifier
     */
    public function find($id) {
        if ($id !== null) {
            if ( ! is_array($id)) {
                $id = array($id);
            } else {
                $id = array_values($id);
            }

            $query  = $this->query." WHERE ".implode(" = ? AND ",$this->primaryKeys)." = ?";
            $query  = $this->applyInheritance($query);

            $params = array_merge($id, array_values($this->options['inheritanceMap']));

            $stmt  = $this->conn->execute($query,$params);

            $this->data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($this->data === false)
                return false;

            return $this->getRecord();
        }
        return false;
    }
    /**
     * applyInheritance
     * @param $where                    query where part to be modified
     * @return string                   query where part with column aggregation inheritance added
     */
    final public function applyInheritance($where) {
        if ( ! empty($this->options['inheritanceMap'])) {
            $a = array();
            foreach ($this->options['inheritanceMap'] as $field => $value) {
                $a[] = $field." = ?";
            }
            $i = implode(" AND ",$a);
            $where .= " AND $i";
        }
        return $where;
    }
    /**
     * findAll
     * returns a collection of records
     *
     * @return Doctrine_Collection
     */
    public function findAll() {
        $graph = new Doctrine_Query($this->conn);
        $users = $graph->query("FROM ".$this->options['name']);
        return $users;
    }
    /**
     * findByDql
     * finds records with given DQL where clause
     * returns a collection of records
     *
     * @param string $dql               DQL after WHERE clause
     * @param array $params             query parameters
     * @return Doctrine_Collection
     */
    public function findBySql($dql, array $params = array()) {
        $q = new Doctrine_Query($this->conn);
        $users = $q->query("FROM ".$this->options['name']." WHERE ".$dql, $params);
        return $users;
    }

    public function findByDql($dql, array $params = array()) {
        return $this->findBySql($dql, $params);
    }
    /**
     * clear
     * clears the first level cache (identityMap)
     *
     * @return void
     */
    public function clear() {
        $this->identityMap = array();
    }
    /**
     * getRecord
     * first checks if record exists in identityMap, if not
     * returns a new record
     *
     * @return Doctrine_Record
     */
    public function getRecord() {
        $this->data = array_change_key_case($this->data, CASE_LOWER);

        $key = $this->getIdentifier();

        if ( ! is_array($key)) {
            $key = array($key);
        }
        foreach ($key as $k) {
            if ( ! isset($this->data[$k])) {
                throw new Doctrine_Exception("Primary key value for $k wasn't found");
            }
            $id[] = $this->data[$k];
        }

        $id = implode(' ', $id);

        if (isset($this->identityMap[$id])) {
            $record = $this->identityMap[$id];
        } else {
            $record = new $this->options['name']($this);
            $this->identityMap[$id] = $record;
        }
        $this->data = array();

        return $record;
    }
    /**
     * @param $id                       database row id
     * @throws Doctrine_Find_Exception
     */
    final public function getProxy($id = null) {
        if ($id !== null) {
            $query = "SELECT ".implode(", ",$this->primaryKeys)." FROM ".$this->getTableName()." WHERE ".implode(" = ? && ",$this->primaryKeys)." = ?";
            $query = $this->applyInheritance($query);

            $params = array_merge(array($id), array_values($this->options['inheritanceMap']));

            $this->data = $this->conn->execute($query,$params)->fetch(PDO::FETCH_ASSOC);

            if ($this->data === false)
                return false;
        }
        return $this->getRecord();
    }
    /**
     * count
     *
     * @return integer
     */
    public function count() {
        $a = $this->conn->getDBH()->query("SELECT COUNT(1) FROM ".$this->options['tableName'])->fetch(PDO::FETCH_NUM);
        return current($a);
    }
    /**
     * @return Doctrine_Query                           a Doctrine_Query object
     */
    public function getQueryObject() {
        $graph = new Doctrine_Query($this->getConnection());
        $graph->load($this->getComponentName());
        return $graph;
    }
    /**
     * execute
     * @param string $query
     * @param array $array
     * @param integer $limit
     * @param integer $offset
     */
    public function execute($query, array $array = array(), $limit = null, $offset = null) {
        $coll  = new Doctrine_Collection($this);
        $query = $this->conn->modifyLimitQuery($query,$limit,$offset);
        if ( ! empty($array)) {
            $stmt = $this->conn->getDBH()->prepare($query);
            $stmt->execute($array);
        } else {
            $stmt = $this->conn->getDBH()->query($query);
        }
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        foreach ($data as $row) {
            $this->data = $row;
            $record = $this->getRecord();
            $coll->add($record);
        }
        return $coll;
    }
    /**
     * sets enumerated value array for given field
     *
     * @param string $field
     * @param array $values
     * @return void
     */
    final public function setEnumValues($field, array $values) {
        $this->options['enumMap'][strtolower($field)] = $values;
    }
    /**
     * @param string $field
     * @return array
     */
    final public function getEnumValues($field) {
        if (isset($this->options['enumMap'][$field])) {
            return $this->options['enumMap'][$field];
        } else {
            return array();
        }
    }
    /**
     * enumValue
     *
     * @param string $field
     * @param integer $index
     * @return mixed
     */
    final public function enumValue($field, $index) {
        if ($index instanceof Doctrine_Null)
            return $index;

        return isset($this->options['enumMap'][$field][$index]) ? $this->options['enumMap'][$field][$index] : $index;
    }
    /**
     * invokeSet
     *
     * @param mixed $value
     */
    public function invokeSet(Doctrine_Record $record, $name, $value) {
        if ( ! ($this->getAttribute(Doctrine::ATTR_ACCESSORS) & Doctrine::ACCESSOR_SET)) {
            return $value;
        }
        $prefix = $this->getAttribute(Doctrine::ATTR_ACCESSOR_PREFIX_SET);
        if (!$prefix)
            $prefix = 'set';

        $method = $prefix . $name;

        if (method_exists($record, $method)) {
            return $record->$method($value);
        }

        return $value;
    }
    /**
     * invokeGet
     *
     * @param mixed $value
     */
    public function invokeGet(Doctrine_Record $record, $name, $value) {
        if ( ! ($this->getAttribute(Doctrine::ATTR_ACCESSORS) & Doctrine::ACCESSOR_GET)) {
            return $value;
        }
        $prefix = $this->getAttribute(Doctrine::ATTR_ACCESSOR_PREFIX_GET);
        if (!$prefix)
            $prefix = 'get';

        $method = $prefix . $name;

        if (method_exists($record, $method)) {
            return $record->$method($value);
        }

        return $value;
    }
    /**
     * enumIndex
     *
     * @param string $field
     * @param mixed $value
     * @return mixed
     */
    final public function enumIndex($field, $value) {
        if ( ! isset($this->options['enumMap'][$field])) {
            $values = array();
        } else {
            $values = $this->options['enumMap'][$field];
        }

        return array_search($value, $values);
    }
    /**
     * getDefinitionOf
     *
     * @return string       ValueWrapper class name on success, false on failure
     */
    public function getValueWrapperOf($column) {
        if (isset($this->columns[$column][2]['wrapper'])) {
            return $this->columns[$column][2]['wrapper'];
        }
        return false;
    }
    /**
     * getColumnCount
     *
     * @return integer      the number of columns in this table
     */
    final public function getColumnCount() {
        return $this->columnCount;
    }

    /**
     * returns all columns and their definitions
     *
     * @return array
     */
    final public function getColumns() {
        return $this->columns;
    }
    /**
     * returns an array containing all the column names
     *
     * @return array
     */
    public function getColumnNames() {
        return array_keys($this->columns);
    }

    /**
     * getDefinitionOf
     *
     * @return mixed        array on success, false on failure
     */
    public function getDefinitionOf($column) {
        if (isset($this->columns[$column])) {
            return $this->columns[$column];
        }
        return false;
    }
    /**
     * getTypeOf
     *
     * @return mixed        string on success, false on failure
     */
    public function getTypeOf($column) {
        if (isset($this->columns[$column])) {
            return $this->columns[$column][0];
        }
        return false;
    }
    /**
     * setData
     * doctrine uses this function internally
     * users are strongly discouraged to use this function
     *
     * @param array $data               internal data
     * @return void
     */
    public function setData(array $data) {
        $this->data = $data;
    }
    /**
     * returns the maximum primary key value
     *
     * @return integer
     */
    final public function getMaxIdentifier() {
        $sql  = "SELECT MAX(".$this->getIdentifier().") FROM ".$this->getTableName();
        $stmt = $this->conn->getDBH()->query($sql);
        $data = $stmt->fetch(PDO::FETCH_NUM);
        return isset($data[0])?$data[0]:1;
    }
    /**
     * returns simple cached query
     *
     * @return string
     */
    final public function getQuery() {
        return $this->query;
    }
    /**
     * returns internal data, used by Doctrine_Record instances
     * when retrieving data from database
     *
     * @return array
     */
    final public function getData() {
        return $this->data;
    }
    /**
     * returns a string representation of this object
     *
     * @return string
     */
    public function __toString() {
        return Doctrine_Lib::getTableAsString($this);
    }
}
