<?php
// vim: set et ts=4 sw=4 fdm=marker:
/*
    (C) Copyright 2008 Jesse Hogan <jessehogan0@gmail.com>
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Lesser General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Lesser General Public License for more details.

    You should have received a copy of the GNU Lesser General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/
/* TODO
    * Changing size of field does is not observed
    * Validate that date is between 1901-12-14 and 2038-01-18
*/

require_once("MDB2.php");
define("BOM_CONSTRUCTIVE_AND_DESTUCTIVE_TABLE_CHANGE", 0);
define("BOM_CONSTRUCTIVE_TABLE_CHANGE", 1);
define("FULL", 0);
define("DATEONLY", 1);
define("TIMEONLY", 2);
$DATE_FORMAT = 'Y-m-d';
$DATETIME_FORMAT = 'Y-m-d H:i:s';
$TIME_FORMAT = 'H:i:s';

function m($assert=null){
    if (is_null($assert) || !$assert){
        echo "XXX\n";
    }
}
function pl($val){
    print("$val\n");
}
class Business_Objects_Manager{
    var $_connections = array();
    var $_tableChangeType=BOM_CONSTRUCTIVE_TABLE_CHANGE;
    var $_DBChangeLog=array();
    var $_logFile;
    function Business_Objects_Manager(){
        /* Singleton, use getInstance() */ 
    }
    function &GetInstance(){
        static $instance=null;
        if (!$instance)
            $instance = new Business_Objects_Manager();
        return $instance;
    }
    function LogFile(){
        if(!isset($this->_logFile)){
            $this->_logFile = fopen('STDOUT', 'w');
        }
        return $this->_logFile;
    }
    function &GetConnection($name){
        $name = ($name=='') ? "default" : $name;
        return $this->_connections[$name];
    }
    function AddDSN($dsn, $name){
        $name = (!$name) ? "default" : $name;
        $conn =& MDB2::singleton($dsn, array('debug' => '1'));
        if (PEAR::iserror($conn)){
            die ($conn->GetMessage());
        }
        $conn->loadModule('Extended');
        if ($name == 'manager'){
           $conn->loadModule('Reverse', null, true);
           $conn->loadModule('Manager', null, true);
        }
        /* Without the below line, accessor db methods returning "" are interpreted as NULL
            when sent to autoExecute which makes it impossable to put an empty string in a 
            field.*/
        $conn->setOption('portability', MDB2_PORTABILITY_ALL ^ MDB2_PORTABILITY_EMPTY_TO_NULL);
        $this->_connections[$name] = &$conn;
    }
    function BusinessBases(){
        $classes = get_declared_classes();
        foreach($classes as $class){
            if (strtolower(get_parent_class($class)) == "business_base"){
                $businessBases[]=$class;
            }
        }
        return $businessBases;
    }
    function UpdateDBStructures(){
        @fprintf(STDOUT, "DBG8 %s/%s\n", __CLASS__, __FUNCTION__);    #SED_DELETE_ON_ROLL
        $defaultConn = $this->_connections['default'];
        $db = $defaultConn->database_name;

        $managerConn = $this->_connections['manager'];
        
        $dbs = $managerConn->listDatabases();
        if (!in_array($db, $dbs)){
            $managerConn->createDatabase($db);
        }

        $this->UpdateTableStructure();
        
    }
    function UpdateTableStructure(){
        @fprintf(STDOUT, "DBG8 %s/%s\n", __CLASS__, __FUNCTION__);    #SED_DELETE_ON_ROLL
        foreach ($this->BusinessBases() as $className){
            $class = new $className();
            $class->UpdateTableStructure();
        }
    }
    function TableChangeType($value=null){
        if (is_null($value)){
            return $this->_tableChangeType;
        }else{

            $this->_tableChangeType = $value;
        }
    }
    function DBChangeLog($msg=null){
        if ($msg)
            $this->_DBChangeLog[] = $msg;
        else
            return $this->_DBChangeLog;
    }
    function ClassFiles(){
        $args = func_get_args();
        for($i=0; $i<func_num_args(); $i++){
            /*TODO:CROSS File Seperator */
            $nodes = explode('/', $args[$i]);
            $filePattern = array_pop($nodes);
            $filePattern = "/".$filePattern."/";
            foreach($nodes as $node){
                $dir .= "/$node";
            }
            if ($dir == '') $dir = '.';

            if (is_dir($dir)){
                if ($dh = opendir($dir)) {
                    while (($file = readdir($dh)) !== false) {
                        $path = "$dir/$file";
                        if ($file == '.' || $file == '..' || filetype($path) != 'file') continue;
                        $res = preg_match($filePattern, $file);
                        if ($res === false){
                            throw new Exception('preg_match() failed on pattern: '.$filePattern.' subject: '.$file);
                        }elseif ($res == 1){
                            require_once("$path");
                        }
                    }
                    closedir($dh);
                }
            }else{
                throw new Exception("Could not find the following directory for class files: $dir");
            }
        }
    }
    function DBChangeLogString($format="HTML"){
        $LF = ($format == "HTML") ? "<BR>" : "\n";
        $TAB = ($format == "HTML") ? "&nbsp;&nbsp;&nbsp;&nbsp;" : "\t";
        $changes = $this->DBChangeLog();
        if (count($changes) > 0){
            $ret = "Table changes:$LF$TAB";
            foreach($changes as $change){
                $ret .= "$change$LF$TAB";
            }
        }
        return $ret;
    }
    function ClearDBChangeLog(){
        $_DBChangeLog=array();
    }
}

class Broken_Rules{
    var $_rules=array();
    function Add($name, $desc){
        $this->_rules[] =
                array($name => $desc);
    }
    function Assert($name, $desc, $isBroken){
        if ($isBroken){
            $this->Add($name, $desc);
        }
    }
    function Append($brokenRules){
        if (is_object($brokenRules)){
            $rules = $brokenRules->Rules();
        }
        if (!empty($rules)){
           $this->_rules =
                    array_merge($this->_rules, $rules);
        }
    }
    function Count(){
        /* TODO:MISSING: A parameter called $where could be added
        which could return a count of all business objects where 
        a certain condition is met. This could get fancy because 
        the $where string could be a nested boolean expression. Example:

        Examples:
            (State(), Country() and City() evaluate to the return value of
            the following respectively: $this->State(); $this->Country(); and 
            $this->City();)
            $cnt = $customer->Count("State() == 'NY'); 
            $cnt = $customer->Count("State() == 'NY' or (Country() == 'UK' && City() == 'London');*/
        return count($this->_rules);
    }
    function Rules(){
        return $this->_rules;
    }
    function ToString(){
        foreach($this->_rules as $rule){
            $name = key($rule);
            $desc = current($rule);
            $ret .= "$name - $desc\n";
        }
        return $ret;
    }
}
class Business_Collection_Base implements Iterator{
    var $_collection=array(); var $_bom; var $_safeWhere=true;
    var $_sortMethod, $_sortOrder;

    public function rewind() {
        reset($this->_collection);
    }

    public function &current() {
        return current($this->_collection);
    }

    public function key() {
        return key($this->_collection);
    }

    public function &next() {
        return next($this->_collection);
    }

    public function valid() {
        return $this->current() !== false;
    }
    function &ParseOrderBy($orderByString){
        $i=0;
        if (strlen(trim($orderByString)) != ''){
            $orderBys = explode(',', $orderByString);
            foreach($orderBys as $orderBy){
                $orderByElements = preg_split('/[\s]+/', trim($orderBy));
                $cnt = count($orderByElements);
                if ($cnt == 1 || $cnt == 2){
                    $orderByArr[$i][0] = trim($orderByElements[0]);
                    if ($cnt == 2){
                        $sortOrder = trim(strtolower($orderByElements[1]));
                        if ($sortOrder != 'desc' && $sortOrder != 'asc'){
                            $parseErr = "Sort order indicator ($sortOrder) incorrect"; break;
                        }
                        $orderByArr[$i++][1] = (($sortOrder) == 'asc' ? SORT_ASC: SORT_DESC);
                    }else{
                        $orderByArr[$i++][1] = SORT_ASC;
                    }
                }else{
                    $parseErr = 'Incorrect number of elements'; break;
                }
            }
        }else{
            $parseErr = 'No order by specified';
        }
        if ($parseErr != ''){
            throw new Exception("Order by parse error ($orderByString): $parseErr");
        }else{
            return $orderByArr;
        }
    }
    public function Sort($orderByString){
        /* TODO: Accessors with params ($person->Bio($locale)) crash program */
        $orderByArr =& $this->ParseOrderBy($orderByString);
        $orderByCnt = count($orderByArr);

        for($i=0; $i<$orderByCnt; $i++){
            $method= $orderByArr[$i][0];
            $this->_sortMethod = $method;
            $this->_sortOrder = $orderByArr[$i][1];
            if ($i==0){
                $this->MergeSort($this->_collection);
            }else{
                $dupCnt=1;
                $prevMethods[] = $orderByArr[$i-1][0];
                $firstLoop=true;
                $cnt = count($this->_collection);
                for($j=0; $j<$cnt; $j++){
                    if (!$firstLoop){
                        $obj =& $this->_collection[$j];
                        for($k=0; $k<$i; $k++){
                            $prevMethod = $prevMethods[$k];
                            $vals[$k] = $obj->$prevMethod();
                        }
                        $dupFound=true;
                        for($k=$i; $k>=0; $k--){
                            if ($cmp = (strnatcasecmp($prevVals[$k], $vals[$k]) != 0)){
                                $dupFound=false; break;
                            }
                        }
                        if ($dupFound){
                            if ($dupCnt == 1) $colSubset[] =& $this->_collection[$j-1];
                            $colSubset[] =& $obj; $dupCnt++;
                        }else{
                            if ($dupCnt > 1){
                                $this->MergeSort($colSubset);
                                array_splice($this->_collection, $j-$dupCnt, $dupCnt, $colSubset);
                                unset($colSubset);
                                $dupCnt=1;
                            }
                        }
                    }else{
                        $firstLoop=false;
                    }
                    $prevVals = $vals;
                }
            }
        }
    }
    public function Merge(&$a1, &$a2){
        $cnt1 = count($a1);
        $cnt2 = count($a2);
        $method = $this->_sortMethod;
        $sortOrder = $this->_sortOrder;
        while ($cnt1 > 0 && $cnt2 > 0) {
            $cmp = strnatcasecmp($a1[0]->$method(), $a2[0]->$method());
            if (($cmp < 1 && $sortOrder == SORT_ASC) || ($cmp > 0 && $sortOrder ==SORT_DESC)){
                $r[] = array_shift($a1);
                $cnt1--;
            }else{
                $r[] = array_shift($a2);
                $cnt2--;
            }
        }
        while ($cnt1 > 0) {
            $r[] = array_shift($a1);
            $cnt1--;
        }
        while ($cnt2 > 0) {
            $r[] = array_shift($a2);
            $cnt2--;
        }
        return $r;
    }

    function MergeSort(&$a){
        $cnt = count($a);
        if ($cnt <= 1) return;
        $half = ($cnt/2); 
        if ($cnt % 2 != 0) $half -= .5;
        $a1 = array_slice($a, 0, $half);
        $a2 = array_slice($a, $half, $cnt-$half); 

        $this->MergeSort($a1);
        $this->MergeSort($a2);
        $a = $this->Merge($a1, $a2);
    }

    public function &First($exceptionIfGreaterThanOne=false){
        if (!empty($this->_collection)){
            if ($exceptionIfGreaterThanOne && $this->Count > 1){
                $className = get_class();
                throw new Exception("Call to method '".__METHOD__."' in class '$className' returned more that one object");
            }
            return $this->_collection[0]; 
        }else{
            return null;
        }
    }
    public function LogFile(){
        $this->_bom = $this->LogFile();
    }
    function Business_Collection_Base($where=null){
        $this->_bom =& Business_Objects_Manager::GetInstance();
        if ($where != null){
            $this->LoadWhere($where);
        }
    }
    function &Connection(){
        if (isset($this->_bom))
            return $this->_bom->GetConnection("default");
        else 
            throw new Exception("BOM not set in " . get_class($this) . ". Ensure collection class calls base constructor");
    }
    function Contains(&$item) 
    {
        if (is_object($item)){
            foreach($this->_collection as $obj){
                if ($obj === $item) return true;
            }
        }elseif(is_numeric($item) || ctype_digit($item)){
            foreach($this->_collection as $obj){
                if ($obj->ID() == $item) return true;
            }
        }
        return false;
    }
    function &Item($id){
        foreach($this->_collection as $obj){
            if ($bo->ID() === $id){
                return $bo;
            }
        }
    }
    function Update() 
    {
        $removes = array();
        $cnt = $this->Count();
        for($i=0; $i<$cnt; $i++){
            $obj =& $this->_collection[$i];
            $className = get_class($obj);
            if($obj->_isMarkedForDeletion){
                $removes[] = $obj;
            }
            $obj->Update();
        }
        foreach($removes as $remove){
            $this->Remove($remove);
        }
    }
    function Business_Object(){
        $myName = get_class($this);
        $tmp =  substr($myName, -3);
        if ($tmp == 'ies'){
            $myBOclass = substr_replace($myName, 'y', -3);
        }else{
            $myBOclass = substr($myName, 0, -1);
        }
        if (class_exists($myBOclass)){
            return $myBOclass;
        }else{
            if (method_exists($this, 'ChildClass')){
                $myBOclass = $this->ChildClass();
                if (class_exists($myBOclass)){
                    return $myBOclass;
                }
            }
            @fprintf(STDOUT, "DBG9 %s/%s Class '$myBOclass' doesn't exist.\n", __CLASS__, __FUNCTION__);    #SED_DELETE_ON_ROLL
            return '';
        }
    }
    function Table(){
        $bo = &$this->NewBO();
        return $bo->Table();
    }
    function &NewBO(){
        $bo = $this->Business_Object();
        if ($bo == ''){
            $msg = "Attempted to instantiated business class for the collection class '" .
                    get_class($this) . "' but wasn't able to infer name. Change Collection and/or BO names or use the ChildClass method'";
            throw new Exception($msg);
        }
        $bo = new $bo();
        return $bo;
    }
    function &GetBy($method, $value){
        @fprintf(STDOUT, "DBG9 %s/%s - method: %s, val: %s\n", __CLASS__, __FUNCTION__, $method, $value);    #SED_DELETE_ON_ROLL
        $thisClass = get_class($this);
        $bos =& new $thisClass();
        foreach($this as $bo){
            $methodRet = $bo->$method();
           @fprintf(STDOUT, "DBG9 %s/%s Testing: %s\n", __CLASS__, __FUNCTION__, $methodRet);    #SED_DELETE_ON_ROLL
            if($methodRet == $value){
               @fprintf(STDOUT, "DBG9 %s/%s Adding: %s\n", __CLASS__, __FUNCTION__, $methodRet);    #SED_DELETE_ON_ROLL
                $bos->Add($bo);
            }
        }
        return $bos;
    }
    function &GetAllRows(){
        $conn =& $this->Connection();
        $res = $conn->extended->autoExecute($this->Table(), 
                                                null, MDB2_AUTOQUERY_SELECT, 
                                                null, null, 
                                                true, array());
        if(PEAR::iserror($res)){
            throw new Exception($res->getUserInfo());
        }
        $rows = $res->fetchAll(MDB2_FETCHMODE_ASSOC);
        $res->Free();
        return $rows;
    }
    function LoadBy($fieldName, $value, $like=false){
       @fprintf(STDOUT, "DBG8 CTOR %s/%s field: %s; val: %s\n", __CLASS__, __FUNCTION__, $fieldName, $value);    #SED_DELETE_ON_ROLL
        // TODO: $where needs to be quoted properly
        if ($like)
            $where = "$fieldName like $value";
        else
            $where = "$fieldName = $value";
        $this->LoadWhere($where);
    }
    function SafeWhere($value=null){
        if ($value!=null) $this->_safeWhere = $value;
        return $this->_safeWhere;
    }
    function LoadWhere($where){
        /* TODO:MISSING: To make code independent of column names, a where clause could have
        function names instead of column names. The function names could be identified by ending
        in () and would be resolved to the column names they are associated with. 
        Ex: "FirstName() = 'Alex' LastName() = 'Meyers'" would be converted to 
            "first_name = 'Alex' and last_name = 'Meyers'"
            Given FirstName() was mapped to first_name
            and   LastName()  was mapped to last_name
        */

        if ($this->SafeWhere()){
            if (strpos($where, ';') !==false || strpos($where, '--') !==false){
                throw new Exception("Insecure where clause: $where");
            }
        }else{
            $this->SafeWhere(true); // Reset
        }
       @fprintf(STDOUT, "DBG8 %s/%s %s\n", __CLASS__, __FUNCTION__, $where);    #SED_DELETE_ON_ROLL
        $conn =& $this->Connection();
        $res = $conn->extended->autoExecute($this->Table(), 
                                                null, MDB2_AUTOQUERY_SELECT, 
                                                $where, null, 
                                                true, array());
        if(PEAR::iserror($res)){
            throw new Exception($res->getUserInfo());
        }
        $rows = $res->fetchAll(MDB2_FETCHMODE_ASSOC);
        $res->Free();
        
        foreach($rows as $row){
            $bo =& $this->MakeBOFromRow($row);
            $bo->LoadChildren();
           @fprintf(STDOUT, "DBG8 %s/%s Collecting: %s\n", __CLASS__, __FUNCTION__, $bo->ID());    #SED_DELETE_ON_ROLL
            //$this->_collection[$bo->ID()] = $bo;
            $this->Add(&$bo);
        }
    }

    function LoadAll(){
       @fprintf(STDOUT, "DBG9 %s/%s\n",__CLASS__,  __FUNCTION__);    #SED_DELETE_ON_ROLL
    	$rows =& $this->GetAllRows();
        foreach($rows as $row){
            $bo =& $this->MakeBOFromRow($row);
            $bo->LoadChildren();
            $this->Add(&$bo);
            //$this->_collection[$bo->ID()] = $bo;
        }
    }
    function &MakeBOFromRow($row){
        $bo = &$this->NewBO();
        $bo->MarkClean();
        foreach($bo->Method2Fields() as $method=>$fieldName){
            if (!($fieldName == 'id')){
                $var = '_'.$method; 
                $bo->$var = $row[$fieldName];
               @fprintf(STDOUT, "DBG9 %s/%s $var-%s\n", __CLASS__, __FUNCTION__, $row[$fieldName]);    #SED_DELETE_ON_ROLL
            }else{
                $bo->_id = $row[$fieldName];
            }
        }
        return $bo;
    }
    function IsDirty ()
    {
        foreach($this->_collection as $businessBase)
            if($businessBase->IsDirty())
                return true;
        return false;
    }
    function &BrokenRules ()
    {
        $brs = new Broken_Rules();
        foreach($this->_collection as $businessBase){
            $brs->Append($businessBase->BrokenRules());
        }
        return $brs;
    }
    function IsValid(){
        $brs = $this->BrokenRules();
        return $brs->Count() == 0;
    }
    function Add(&$bo){
       @fprintf(STDOUT, "DBG9 %s/%s\n",__CLASS__, __FUNCTION__);    #SED_DELETE_ON_ROLL
        $bo->_collection = $this;
        $this->_collection[] = $bo;    
    }
    function Append(&$bos){
        foreach($bos as $bo){
            $this->_collection[] = $bo;
        }
    }
    function Remove(&$item)
    {
        $cnt = $this->Count();
        $tmp = array();
        for($i=0; $i<$cnt; $i++){
            $test = $this->_collection[$i];
            if ($item->ID() != $test->ID()){
                $tmp[] = $test;
            }
        }
        $this->_collection =& $tmp;
    }
    function Count(){
        return count($this->_collection);
    }
    function Delete(){
        $cnt = $this->Count();
        for($i=0; $i<$cnt; $i++){
           $obj =& $this->_collection[$i];
           $obj->Delete();
           $this->Remove($obj);
        }
    }
    function PrintState($format="html"){
        foreach($this->_collection as $bo){
           $bo->PrintState($format); 
           echo("\n");
        }
    }
    function PrintStateDie($format="html"){
        $this->PrintState($format);
        die;
    }
    function UpdateMethod($method, $value){
    }
}
class Business_Base
{
    var $_row; var $_table; var $_isNew; var $_isDirty;
    var $_isEmpty;
    var $_isMarkedForDeletion; var $_bom; var $_conn;
    var $_managerConn; var $_reverse;

    var $_DBFields=array();
    var $_method2Fields=array();
    var $_constraints=array();
    var $_indices=array();
    var $_dbTypes=array();
    var $_collection;
    var $_printingState;

    function Business_Base($condition=null)
    {
        $this->_bom =& Business_Objects_Manager::GetInstance();
        $this->_conn =& $this->_bom->GetConnection("default");
        $this->_managerConn =& $this->_bom->GetConnection("manager"); 
        $this->_reverse = $this->_managerConn->reverse;
        if(trim($this->_conn->database_name) == '') throw new Exception('BOM\'s default DB isn\'t set');
        $this->_managerConn->setDatabase($this->_conn->database_name);
        if ($condition){
            if (is_numeric($condition)){
                # $this->_id = $condition;
                $this->Business_Base0($condition);
            }else{
                $this->LoadWhere($condition);
            }
            if (!$this->IsEmpty()){
                $this->LoadChildren();
            }
        }else{ 
            $this->MarkNew();
        }
    }
    function LoadChildren(){
        $children =& $this->Children();
        foreach($children as $child){
            $FK = $child['fk']; $bos =& $child['bos'];
            $ID = $this->ID();
            $class = get_class($bos);
            @fprintf(STDOUT, "DBG8 %s/%s Loading $class where $FK=$ID\n", __CLASS__, __FUNCTION__);    #SED_DELETE_ON_ROLL
            $bos->LoadBy($FK, $ID);
        }
    }
    function Business_Base0($id)
    {
        $conn =& $this->Connection();
        $res = $conn->extended->autoExecute($this->Table(), 
                                                null, MDB2_AUTOQUERY_SELECT, 
                                                "id = $id", null, 
                                                true, array());
        if(PEAR::iserror($res)){
            throw new Exception($res->userinfo);
        }
        $row = $res->fetchRow(MDB2_FETCHMODE_ASSOC);
        if (is_null($row)){
            $this->MarkEmpty();
        }
        $this->_id = $row['id'];
        $this->PopulateBusinessObject($row);
        $this->MarkClean();
    }
    function LoadWhere($condition)
    {
        $conn =& $this->Connection();
        $res = $conn->extended->autoExecute($this->Table(), 
                                                null, MDB2_AUTOQUERY_SELECT, 
                                                $condition, null, 
                                                true, array());
        if(PEAR::iserror($res)){
            throw new Exception($res->userinfo);
        }
        $rows = $res->fetchAll(MDB2_FETCHMODE_ASSOC);

        $rowCount=0;
        foreach($rows as $row){
            $rowCount++;
        }
        if($rowCount == 0){
            $this->MarkEmpty();
        }elseif($rowCount == 1){
            $this->_id = $row['id'];
            $this->PopulateBusinessObject($row);
            $this->MarkClean();
        }elseif($rowCount > 1){
            throw new Exception("Condition: '$condition' caused more than one row to be returned for class: " . get_class($this));
        }
    }

    function PopulateBusinessObject($row){
        foreach($this->Method2Fields() as $method=>$fieldName){
            $var = '_'.$method; 
            $this->$var = $row[$fieldName];
        }
    }

    function MarkEmpty()
    {
        $this->_isEmpty = true;
        $this->MarkNew();

    }
    function MarkNew()
    {
        $this->_isNew = true;
        $this->_isMarkedForDeletion = false;
        $this->MarkDirty();
    }
    function MarkDirty()
    {
        $this->_isDirty = true;
    }
    function IsValid(){
        $brs = $this->BrokenRules();
        return $brs->Count() == 0;
    }
    function MarkClean()
    {
        $this->_isDirty = false;
        $this->_isNew = false;
        $this->_isMarkedForDeletion = false;
    }
    function MarkForDeletion()
    {
        if (!$this->_isNew){
            $this->_isMarkedForDeletion = true;
            $this->_isDirty = true;
        }else{
            $class = get_class($this);
            throw new Exception("Attempted to mark a new object for deletion in class: '$class'");
        }
    }
    function BrokenRules(){
        /* TODO:MISSING Rule messages shouldn't be in English */
        $brs = new Broken_Rules();
        if (!$this->IsDirty()) return $brs;
        $children =& $this->Children();
        foreach($children as $child){
            $bos =& $child['bos'];
            $br = $bos->BrokenRules();
            $brs->Append(&$br);
        }
        foreach($this->Method2Fields() as $method=>$fieldName){
            if ($fieldName != 'id'){
                $field = $this->_DBFields[$fieldName];
                $val = $this->$method();
                $type = $field['type'];
                $unsigned = $field['unsigned'];
                $length = $field['length'];
                $notnull = $field['notnull'];
                $isNumber = ($type == 'integer' || $type == 'decimal' ||
                             $type == 'float');
                $className = get_class($this);
                $stdErrMsg = "Inevitable DB Validation error prevented at $className -> $method";
                if (is_null($val)){
                    if ($notnull){
                        $brs->Add('NULL', "$stdErrMsg; Value can't be null ($val)");
                    }
                }else{
                    switch($type){
                        case 'text':
                            if (strlen($val) > $length){
                                $brs->Add('WRONG_LENGTH', "$stdErrMsg; Expected length: $length; Actual length: ".strlen($val));
                            }
                            break;
                        case 'boolean':
                            $brs->Assert('NOT_BOOLEAN', "$stdErrMsg; Expected type: boolean; Actual value: '$val'", (!is_bool($val) && $val != 0 && $val != 1));
                            break;
                        /*  These numeric types could be more finely tuned. 
                            The types are db dependent so it's difficult to determine exactly how to validate each one
                            so we will just use is_numeric for now*/
                        case 'integer':
                        case 'decimal':
                        case 'float':
                            $brs->Assert('NOT_NUMERIC', "$stdErrMsg; Expected type: numeric; Actual value: '$val'", !is_numeric($val));
                            if ($unsigned && $val < 0){
                                $brs->Add('NEGATIVE', "$stdErrMsg; Expected unsigned value; Actual value '$val'");
                            }
                            break;
                        case 'timestamp':
                            $numericVal = strtotime($val);
                            if ($numericVal === false || $numericVal == -1){
                                $brs->Add('NOT_DATE', "$stdErrMsg; Expected type: timestamp; Actual value: '$val'");
                            }
                            break;
                        case 'time':
                            /* This is contrained to military time. Should be more forgiving */
                            $arr = explode(':', $val);
                            if ( ! (
                                    count($arr) == 3 &&
                                    is_numeric($arr[0]) &&
                                    is_numeric($arr[1]) &&
                                    is_numeric($arr[2]) &&
                                    $arr[0] >= 0 && $arr[0] <= 23 &&
                                    $arr[1] >= 0 && $arr[1] <= 59 &&
                                    $arr[2] >= 0 && $arr[2] <= 59)
                               ){
                                /* Err msg should include format tips */
                                $brs->Add('NOT_TIME', "$stdErrMsg; Expected type: time; Actual value: '$val'");
                            }
                            break;
                        case 'date':
                            $numericVal = strtotime($val);
                            if ($numericVal === false || $numericVal == -1){
                                $brs->Add('NOT_DATE', "$stdErrMsg; Expected type: date; Actual value: '$val'");
                            }
                        case 'clob':
                            break;
                        case 'blob':
                            break;
                    }
                }
            }
        }
        if (method_exists($this, '_BrokenRules')){
            $privBrs =& $this->_BrokenRules();
            $brs->Append($privBrs);
        }
        return $brs;
    }
    function Children(){
       @fprintf(STDOUT, "DBG8 %s/%s\n", __CLASS__, __FUNCTION__);    #SED_DELETE_ON_ROLL
        if (empty($this->_children)){
            if (method_exists($this, 'DiscoverChildren')){
               @fprintf(STDOUT, "DBG8 %s/%s Method Exists\n", __CLASS__, __FUNCTION__);    #SED_DELETE_ON_ROLL
                $this->DiscoverChildren();
            }else{
                return array();
            }
        }
        return $this->_children;
    }
    function AddChild(&$bos, $fkProperty){
        $className = get_class($bos);
        @fprintf(STDOUT, "DBG8 %s/%s Adding: %s\n", __CLASS__, __FUNCTION__, $className);    #SED_DELETE_ON_ROLL
        $child = array('fk'=>$fkProperty, 'bos'=>$bos);
        $this->_children[] = $child;
    }
    function IsMarkedForDeletion(){
        return $this->_isMarkedForDeletion;
    }
    function IsDirty(){
        return $this->_isDirty;
    }
    function IsEmpty(){
        return $this->_isEmpty;
    }
    function IsNew(){
        return $this->_isNew;
    }
    function &Connection(){
        return $this->_conn;
    }
    function &ManagerConnection(){
        return $this->_managerConn;
    }
    function UpdateChildren(){
        /* Update all $this' child collection objects */
       @fprintf(STDOUT, "DBG9 %s/%s\n", __CLASS__, __FUNCTION__);    #SED_DELETE_ON_ROLL
        /* Set the foreign key property of each of the collection
            objects elements to $this'es PK if new (create the parent-
            child relationship */
        $children =& $this->Children();
        if (empty($children)){
           @fprintf(STDOUT, "DBG9 %s/%s No children; returning\n", __CLASS__, __FUNCTION__);    #SED_DELETE_ON_ROLL
            return;
        }
        foreach($children as $child){
            $FK = $child['fk'];
            $bos =& $child['bos'];
            @fprintf(STDOUT, "DBG9 %s/%s Updating: $FK(%s) \n", __CLASS__, __FUNCTION__, get_class($bos));    #SED_DELETE_ON_ROLL
            $beenHere=false;
            foreach($bos as $bo){
                if ($bo->IsNew()){
                    if (!$beenHere){ /* all collection elements will have the 
                                        same FK field */
                        $FKMethod = $bo->GetDBMethod($FK);
                        $beenHere=true;
                    }
                   @fprintf(STDOUT, "DBG9 %s/%s Setting $FKMethod: %s to %s \n", __CLASS__, __FUNCTION__, $FKMethod, $this->ID());    #SED_DELETE_ON_ROLL
                    $bo->$FKMethod($this->ID());
                }
               @fprintf(STDOUT, "DBG9 %s/%s Finally updating child\n", __CLASS__, __FUNCTION__);    #SED_DELETE_ON_ROLL
                $bo->Update();
            }
        }
    }
    function GetDBMethod($methodToFind){
       @fprintf(STDOUT, "DBG9 %s/%s ('%s')\n", __CLASS__, __FUNCTION__, $methodToFind);    #SED_DELETE_ON_ROLL
        $fields = $this->Method2Fields();
        foreach($fields as $method=>$field){
           @fprintf(STDOUT, "DBG9 %s/%s testing $methodToFind -> $method\n", __CLASS__, __FUNCTION__);    #SED_DELETE_ON_ROLL
            if ($methodToFind == $method){
               @fprintf(STDOUT, "DBG9 %s/%s Found $method\n", __CLASS__, __FUNCTION__);    #SED_DELETE_ON_ROLL
                return $method;
            }
        }
        return '';
    }
    function Update()
    {
       @fprintf(STDOUT, "DBG9 %s/%s table: %s\n", __CLASS__, __FUNCTION__, $this->Table());    #SED_DELETE_ON_ROLL
        if (!$this->IsValid()){
            $this->PrintStateDie();
            /* TODO:BUG: Broken rules message bellow doesn't work anymore */
            $className = get_class($this);
            $brs =& $this->BrokenRules();
            $rules =& $brs->Rules();
            foreach($rules as $rule){
                $name = key($rule);
                $desc = current($rule);
                $msg .= $name . ' - ' . $desc . "<br>\n";
            }
            $msg = $brs->ToString();

            throw new Exception("Attempted to update invalid object $className <br>\n $msg");
        }
        $conn =& $this->Connection();
        /*
        print_r($this->FieldValues());
        print_r("<br>");
        print_r($this->DBTypes());
        */
        if ($this->_isNew){  
           @fprintf(STDOUT, "\tINSERT", $this->Table());    #SED_DELETE_ON_ROLL
            $affected = $conn->extended->autoExecute($this->Table(), 
                                                        $this->FieldValues(), MDB2_AUTOQUERY_INSERT,
                                                        null, $this->DBTypes());
            if (PEAR::iserror($affected)){
                throw new Exception("INSERT for " . get_class($this) . "\n" . $affected->userinfo);
            }
            $tmpID = $conn->lastInsertID();

            if (PEAR::iserror($tmpID))
                die ($tmpID->userinfo);
            else
                $this->_id = $tmpID;

        }elseif($this->_isDirty){

            if ($this->_isMarkedForDeletion){
                if (!is_numeric($this->_id)){ // Don't want to delete all the rows in a table so added this check in case something went wrong
                    $class = get_class($this); $id = $this->_id;
                    throw new Exception("In class '$class' an attempt was made to delete the object using '$id' as the identifier");
                }
               @fprintf(STDOUT, "\tDELETE", $this->Table());    #SED_DELETE_ON_ROLL
                $affected = $conn->extended->autoExecute($this->Table(), null, MDB2_AUTOQUERY_DELETE,
                                            "id = $this->_id");
            }else{
               @fprintf(STDOUT, "\tUPDATE", $this->Table());    #SED_DELETE_ON_ROLL
                $affected = $conn->extended->autoExecute($this->Table(), $this->FieldValues(), MDB2_AUTOQUERY_UPDATE,
                                                "id = $this->_id", $this->DBTypes());
            }
            if (PEAR::iserror($affected)){
                throw new Exception("UPDATE for " . get_class($this) . "\n" . $affected->userinfo);
            }
        }
        $this->MarkClean();
        $this->UpdateChildren();
    }
    function Delete()
    {
        $this->MarkForDeletion();
        $this->Update();
    }
    function PrintStateDie($format="html"){
        $this->PrintState($format);
        die;
    }
    function PrintState($format="html"){
        /* This allows calling $this->PrintState() from a method in $this */
        if (! $this->_printingState){ 
            $this->_printingState = true;
            $lb = ($format=="html") ? "<br>" : "\n";
            $t = ($format=="html") ? "&nbsp;&nbsp;&nbsp;&nbsp;" : "$t";
            printf ("CLASS: %s$lb", get_class($this));
            printf($t . "IsDirty " . (($this->_isDirty) ? 'Yes' : 'No') . "$lb");
            printf($t . "IsNew " . (($this->_isNew) ? 'Yes' : 'No') . "$lb");
            printf($t . "IsMarkedForDeletion " . (($this->_isMarkedForDeletion) ? 'Yes' : 'No') . "$lb");
            printf($t . "IsEmpty " . (($this->IsEmpty()) ? 'Yes' : 'No') . "$lb");

            foreach($this->Method2Fields() as $method=>$fieldName){
                $val = $this->$method();
                printf($t."%s() = %s$lb", $method, $val);
            }
            $brs =& $this->BrokenRules();
            $brs =& $brs->Rules();
            @fprintf(STDOUT, "$t"."BrokenRules$lb");    #SED_DELETE_ON_ROLL
            foreach($brs as $br){
                $name = key($br);
                $desc = current($br);
               @fprintf(STDOUT, "$t$t"."%s - %s$lb", $name, $desc);    #SED_DELETE_ON_ROLL
            }
        }
        $this->_printingState = false;
    }
    function FieldValues(){
        foreach($this->Method2Fields() as $method=>$fieldName){
            if (!($fieldName == 'id')){
                $fieldValues[$fieldName] = $this->$method();
            }
        }
        return $fieldValues;
    }
    function DBFields()
    {
        if (empty($this->_DBFields))
            $this->DiscoverTableDefinition();
        return $this->_DBFields;
    }
    function AddIndex($ixName, $fieldsDefinitions){
        $fieldDefinitions = preg_split("/; */", $fieldsDefinitions);
        foreach($fieldDefinitions as $def){
            $def = preg_split("/, */", $def);
            $name = $def[0];
            $sorting = $def[1];
            $len = $def[2];
            $sorting = $sorting == 'asc' ? 'ascending' :  $sorting;
            $sorting = $sorting == 'desc' ? 'descending' :  $sorting;
            $fields[$name] = array('sorting' => $sorting, 
                                            'length' => $len);
        }
        $definition = array('fields' => $fields);
        $this->_indices[$ixName] = $definition;
        
    }
    function AddConstraint(){
        $args = func_get_args();
        $constraintName = $args[0];
        $constraint = $args[1];
        $definition = array($constraint => true);
        for($i=2; $i<func_num_args(); $i++){
            $fieldName = func_get_arg($i);
            $definition['fields'][$fieldName] = array();
        }
        $this->_constraints[$constraintName] = $definition;
    }
    function AddAutoincrementIntegerID(){
        $this->AddMap('id', 'id', 'integer', true, true, 0, 4, 1); 
    }
    function AddTimestampMap($method, $fieldName, $not_null=null, $default=null){
        $not_null = (is_null($not_null)) ? false : $not_null;
        if ($not_null){
            if (!is_null($default)){
                throw new Exception("Tried to use a non-null default on a null field: $fieldName; table: $table");
            }
        }
        $this->AddMap(  $method,   $fieldName, 'timestamp', null, 
                        $not_null, $default,    null,          null);
    }
    function AddBooleanMap($method, $fieldName, $not_null=null, $default=null){
        $not_null = ($not_null==null) ? true : $not_null;
        $default = ($default==null) ? false : $default;
        $this->AddMap(  $method,   $fieldName, 'boolean', null, 
                        $not_null, $default,  1, null);
    }
    function AddIntegerMap($method, $fieldName){
        /* TODO:MISSING: Add validation */
        $this->AddMap($method, $fieldName, 'integer',   true, 
                      true,    0,           4,          false);
    }
    function AddDateMap($method, $fieldName, $not_null=null, $default=null){
        $this->AddMap($method, $fieldName, 'date', null, 
                      $not_null,   $default, null,  null);
    }
    function AddTimeMap($method, $fieldName, $not_null=null, $default=null){
        $this->AddMap($method, $fieldName, 'time', null, 
                      $not_null,   $default, null,  null);
    }
    function AddTextMap($method, $fieldName, $length=null, $not_null=true, $default=""){
        $length = ($length== null) ? 255 : $length;
        $this->AddMap($method, $fieldName, 'text',   null, 
                      $not_null, $default,  $length, null);
    }
    function AddMap($method,  $fieldName, $type,   $unsigned, 
                    $notNull, $default,   $length, $autoincrement){
        $fieldName = strtolower($fieldName);
        $isNumber = ($type == 'integer' || $type == 'decimal' ||
                     $type == 'float');

        $validType = array('text', 'boolean', 'integer', 'decimal', 'float', 'timestamp', 'time', 'date', 'clob', 'blob');
        if (!in_array($type, $validType)){
            throw new 
                Exception("DBType: $type isn't valid for field $fieldName for table " .
                            $this->Table());
        }
        $field['type'] = $type;
        if($isNumber){
            $field['unsigned'] = $unsigned;
            $field['autoincrement'] = $autoincrement;
        }
        $field['length'] = $length;
        $field['notnull'] = $notNull;
        $field['default'] = $default;

        if (!array_key_exists($fieldName, $this->_DBFields)){
            $this->_DBFields[$fieldName] = $field;
            $this->_method2Fields[$method] = $fieldName;
            if ($fieldName != 'id')
                $this->_dbTypes[] = $type;
        }else{
            die(__FUNCTION__ . ": Attempted to add fieldName '$fieldName' but it already exists\n");
        }
    }
    function GetFieldProp($field, $prop){
        if (empty($this->_DBFields))
            $this->DiscoverTableDefinition();
        return $this->_DBFields[$field][$prop];
    }
    public function LogFile(){
        $this->_bom = $this->LogFile();
    }
    function UpdateTableStructure(){
       @fprintf(STDOUT, "DBG8 %s/%s\n", __CLASS__, __FUNCTION__);    #SED_DELETE_ON_ROLL
        $this->_bom->ClearDBChangeLog();
        $this->UpdateTableFields();
        $this->UpdateTableConstraints();
        $this->UpdateTableIndices();
    }
    function UpdateTableIndices(){
        /* TODO: If a user changes an index definition (sort order, length) the change
        is not currently applied. IndexDefinition() is written here to get the existing
        def but it's not working for some reason */
        $existingIndices = $this->_managerConn->manager->listTableIndexes($this->Table());
        foreach($this->Indices() as $name=>$definition){
            if(!in_array(strtolower($name), $existingIndices)){
                $this->_bom->DBChangeLog("Creating index $name");
                $err = $this->_managerConn->manager->createIndex($this->Table(), 
                                                   $name, $definition);
                if (PEAR::isError($err)){
                    throw new Exception($err->getMessage());
                }
            }
        }
        if ($this->_bom->TableChangeType() == BOM_CONSTRUCTIVE_AND_DESTUCTIVE_TABLE_CHANGE){
            foreach($existingIndices as $name){
                if (!array_key_exists(strtolower($name), array_change_key_case($this->Indices(), CASE_LOWER))){
                    $this->_bom->DBChangeLog("Droping index $name");
                    $err = $this->_managerConn->manager->dropIndex($this->Table(), 
                                                       $name);
                    if (PEAR::isError($err)){
                        throw new Exception($err->getMessage());
                    }
                }
            }
        }
    }
    function UpdateTableConstraints(){
        $existingConstraints = $this->_managerConn->manager->listTableConstraints($this->Table());
        foreach($this->Constraints() as $constraintName=>$definition){
            if (!in_array($constraintName, $existingConstraints)){
                $this->_bom->DBChangeLog("Creating constraint $constraintName");
                $err = $this->_managerConn->manager->createConstraint($this->Table(), 
                                                   $constraintName, $definition);
                if($err){
                    die ($err->getMessage());
                }else{
                    echo ("Created $constraintName\n");
                }
            }
        }
        if ($this->_bom->TableChangeType() == BOM_CONSTRUCTIVE_AND_DESTUCTIVE_TABLE_CHANGE){
            foreach($existingConstraints as $constraintName){
                if ($constraintName == 'primary') continue;


                if (!array_key_exists($constraintName, $this->Constraints())){
                    $this->_bom->DBChangeLog("Droping constraint $constraintName");
                    $err = $this->_managerConn->manager->dropConstraint($this->Table(), 
                                                       $constraintName, $isPrimary);
                    if($err){
                        die ($err->getMessage());
                    }else{
                        echo ("Deleted $constraintName\n");
                    }
                }
            }
        }
    }
    function UpdateTableFields(){
        /*TODO:BUG: Length on text type doesn't change */
       @fprintf(STDOUT, "DBG9 %s/%s table: %s\n", __CLASS__, __FUNCTION__, $this->Table());    #SED_DELETE_ON_ROLL
        $conn =& $this->ManagerConnection();
        $manager = $conn->manager;
        // 3rd param needs to be $options.
        $existingTable = $this->_reverse->tableInfo($this->Table());
        $DBFields = $this->DBFields();
        if ($existingTable){
            if ($existingTable->code == -18){
                $this->_bom->DBChangeLog("Table: ".$this->Table()." not found. Creating...");
                $create = true;
                if (count($DBFields) < 2){
                    $this->_bom->DBChangeLog("\tERROR: Table must have at least 2 fields");
                    $create = false;
                }
                if (!array_key_exists('id', $DBFields)){
                    $this->_bom->DBChangeLog("\tERROR: Table must have an id field");
                    $create = false;
                }
                if ($create){
                    $err = $manager->createTable($this->Table(), $this->DBFields());
                    if (PEAR::iserror($err)){
                        throw new Exception($err->getUserInfo());
                    }
                }else{
                    $this->_bom->DBChangeLog("\tERROR: Not creating");
                }
            }else{
                if (count($DBFields) < count($existingTable)){
                    $this->_bom->DBChangeLog("ERROR: The BO table definition for table '" . $this->Table() . "' has fewer columns than the existing table. Columns must be deleted manaually");
                }
                reset($existingTable); reset($DBFields);
                $proposedFieldEOF = empty($DBFields);
                $existingFieldEOF = empty($existingTable);
                while(!($proposedFieldEOF && $existingFieldEOF)){
                    if (!$proposedFieldEOF){
                        $proposedFieldName = key($DBFields);
                        $proposedField = current($DBFields);
                        if ($existingFieldEOF){
                            $this->_bom->DBChangeLog("Adding field '$proposedFieldName' to table '" . $this->Table() . "'");
                            $addFields[$proposedFieldName] = $proposedField;
                        }
                    }
                    if (!$existingFieldEOF){
                        $existingField = current($existingTable);
                        $existingFieldName = $existingField['name'];
                        if (!$proposedFieldEOF){
                            if ($existingFieldName == $proposedFieldName){
                                If ($this->_bom->TableChangeType() ==
                                        BOM_CONSTRUCTIVE_AND_DESTUCTIVE_TABLE_CHANGE){
                                    $existingType = ($existingField['type']  == 'tinyint') ? 'boolean' : $existingField['mdb2type'];
                                    $proposedType = $proposedField['type'];

                                    if (
                                        $proposedType != $existingType || 
                                        $proposedField['unsigned'] != $existingField['unsigned'] ||
                                        $proposedField['autoincrement'] != $existingField['autoincrement'] ||
                                        $proposedField['default'] != $existingField['default'] ||
                                        $proposedField['length'] != $existingField['length'] 
                                    ){
                                        $msg = "Changing field: '$proposedFieldName' in table: '" . $this->Table() . "'";
                                        if ($proposedType != $existingType)
                                            $msg .= ": datatype: " . $existingType . " -> " . $proposedType;
                                        if ($proposedField['unsigned'] != $existingField['unsigned'])
                                            $msg .= ": unsigned " . $existingField['unsigned'] . ' -> ' . $proposedField['unsigned'];
                                        if ($proposedField['autoincrement'] != $existingField['autoincrement'])
                                            $msg .= ": autoincrement " .  $existingField['autoincrement'] . ' -> ' . $proposedField['autoincrement'];
                                        if ($proposedField['default']  != $existingField['default'])
                                            $msg .= ": default '" . $existingField['default'] . "' -> '" . $proposedField['default'] . "'";
                                        if ($proposedField['length']  != $existingField['length'])
                                            $msg .= ": length '" . $existingField['length'] . "' -> '" . $proposedField['length'] . "'";
                                        $this->_bom->DBChangeLog($msg);

                                        $newField['name'] = $proposedFieldName;
                                        $newField['definition'] = $proposedField;
                                        $changeFields[$existingFieldName] = $newField;
                                    }
                                }else{
                                    /* TODO: field properties can be evaluated to 
                                     * to see if the destroy data */
                                }
                            }else{
                                $newField['name'] = $proposedFieldName;
                                $newField['definition'] = $proposedField;
                                /*TODO:BUG: If a column is renamed and if other properites are change only the rename will be report to DBChangeLog */
                                $this->_bom->DBChangeLog("Renaming field: '$existingFieldName' to '$proposedFieldName' in table: '" . $this->Table() . "'");
                                $renameFields[$existingFieldName] = $newField;
                            }
                        }
                    }
                    $proposedFieldEOF = !next($DBFields);
                    $existingFieldEOF = !next($existingTable);
                }
                $changes = array('name'=>$this->Table());
                $changes['add'] = $addFields;
                $changes['rename'] = $renameFields;
                $changes['change'] = $changeFields;
                $err = $manager->alterTable($this->Table(), $changes, false);
                if (PEAR::iserror($err)){
                    echo $msg;
                    print_r($changes);
                    throw new Exception($err->getUserInfo());
                }
            }
        }
    }
    function Indices(){
        if (empty($this->_DBFields)){
            $this->DiscoverTableDefinition();
        }
        return $this->_indices;
    }
    function DBTypes(){
        if (empty($this->_DBFields)){
            $this->DiscoverTableDefinition();
        }
        return $this->_dbTypes;
    }
    function Method2Fields($method=null){
        if ($method == null){
            if (empty($this->_DBFields)){
                $this->DiscoverTableDefinition();
                foreach($this->_method2Fields as $method=>$field){
                    $this->_method2Fields[$method] =
                        strtolower($field);
                }
            }
            return $this->_method2Fields;
        }else{
            $m2f = $this->Method2Fields();
            foreach($m2f as $method0=>$fieldName){
                if ($method == $method0){
                    return $fieldName;
                }
            }
            return "";
        }
    }
    function Constraints(){
        if (empty($this->_DBFields)){
            $this->DiscoverTableDefinition();
        }
        return $this->_constraints;
    }
    function SetVar($method, $value, $format=null){
        $type = $this->Method2Prop($method, 'type');
        $varName = '_'.$method;
        if (!is_null($value)){
            if ($type == 'date' || $type == 'timestamp' || $type == 'time'){
                if (trim($value) != ""){
                    if (!is_numeric($value)){
                        $tmp = $value;
                        $value = strtotime($value);
                        if ($value === false || $value == -1){ // invalid time
                            /* Make value the invalid string so BrokenRules()
                            can detect it */
                            $value = $tmp; 
                        }
                    }
                }else{
                    $value = null;
                    if (is_null($value)){
                    }
                }
            }
            if (!isset($this->$varName) || $this->$varName != $value){
                $this->$varName = $value;
                $this->MarkDirty();
            }
            $ret = $this->$varName;
        }else{
            // Use data definition to determine default
            if (!isset($this->$varName)){
                foreach($this->Method2Fields() as $method=>$fieldName){
                    $curVarName = '_'.$method; 
                    if ($curVarName == $varName){
                        foreach($this->_DBFields as $fieldName0=>$field){
                            if(strtolower($fieldName0) == $fieldName){
                                $this->$varName = $field['default'];
                                $ret = $this->$varName;
                            }
                        }
                        break;
                    }
                }
            }else{
                $ret = $this->$varName;
                $doFormat=true;
                if ($type == 'date' || $type == 'timestamp' || $type == 'time'){
                    if (!is_numeric($ret)){
                        $tmp = $ret;
                        $ret = strtotime($ret);
                        if ($ret === false || $ret == -1){
                            /* Make value the invalid string so BrokenRules()
                            can detect it */
                            $ret = $tmp;
                            $doFormat=false;
                        }
                    }
                    if ($ret != ""){
                        if ($doFormat){
                            if ($format == null){
                                switch ($type){
                                    case 'date':
                                        $format = 'Y-m-d';
                                        break;
                                    case 'timestamp':
                                        $format = 'Y-m-d H:i:s';
                                        break;
                                    case 'time':
                                        $format = 'H:i:s';
                                        break;
                                }
                            }
                            $ret = date($format, $ret);
                        }
                    }else{
                        $ret = null;
                    }
                }
            }
        }
        if (is_null($ret)){
            return null;
        }else{
            return trim($ret);
        }
    }
    function Method2Prop($method, $prop){
        $fieldName = $this->Method2Fields($method);
        return $this->GetFieldProp($fieldName, $prop);
    }
    function SetTable($table){
        $this->_table = $table;
    }
    function ID(){
        return $this->_id;
    }
    function TableDefinition(){
        return $this->_reverse->tableInfo($this->Table(), MDB2_TABLEINFO_ORDER);
    }
    function IndexDefinition($ix){
        $ret = $this->_reverse->getTableIndexDefinition($this->Table(), $ix);
        if (PEAR::iserror($ret))
            throw new Exception($ret->getMessage());
        else
            return $ret;
    }
    function &Collection(){
       @fprintf(STDOUT, "DBG9 %s/%s\n",__CLASS__, __FUNCTION__);    #SED_DELETE_ON_ROLL
        return $this->_collection;
    }
    function HasCollection(){
        return isset($_collection);
    }
}
?> 
