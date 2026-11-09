<?php
declare(strict_types=1);
require __DIR__ . '/app.php';
requireRole(['admin', 'user']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function slotJson(int $status, array $payload): never { http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') slotJson(405, ['success'=>false,'error'=>'Use the assignment form to make changes.']);
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) slotJson(400, ['success'=>false,'error'=>'Your session token is invalid. Refresh and try again.']);
$action = $_POST['action'] ?? '';
$tid = filter_input(INPUT_POST, 'timetable_id', FILTER_VALIDATE_INT) ?: 0;
$day = filter_input(INPUT_POST, 'day_id', FILTER_VALIDATE_INT) ?: 0;
$hour = filter_input(INPUT_POST, 'hour_id', FILTER_VALIDATE_INT) ?: 0;
$dept = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT) ?: 0;
$sem = filter_input(INPUT_POST, 'semester_id', FILTER_VALIDATE_INT) ?: 0;
if (!$tid || !in_array($action, ['add','update','delete','clear_row','clear_column'], true)) slotJson(400, ['success'=>false,'error'=>'The timetable slot is invalid.']);
$stmt=$pdo->prepare('SELECT allow_editing FROM timetables WHERE id=?'); $stmt->execute([$tid]); $editing=$stmt->fetchColumn();
if ($editing===false) slotJson(404,['success'=>false,'error'=>'Timetable was not found.']);
$admin=($_SESSION['user']['role']??'')==='admin';
$userDepartmentIds = array_map('intval', $_SESSION['user']['department_ids'] ?? []);
if ($action==='clear_row' || $action==='clear_column') {
 if(!$admin && !(int)$editing)slotJson(403,['success'=>false,'error'=>'This timetable is read only.']);
 $clearWhere='a.timetable_id=?';$clearParams=[$tid];
 if($action==='clear_row'){
  if(!$day||!$dept||!$sem)slotJson(400,['success'=>false,'error'=>'Choose a valid timetable row.']);
  if(!$admin&&!in_array($dept,$userDepartmentIds,true))slotJson(403,['success'=>false,'error'=>'You cannot clear assignments for this department.']);
  foreach([['days','day_id',$day],['departments','id',$dept]] as [$table,$column,$value]){$check=$pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column}=?");$check->execute([$value]);if(!(int)$check->fetchColumn())slotJson(400,['success'=>false,'error'=>'The timetable row is invalid.']);}
  $stmt=$pdo->prepare('SELECT COUNT(*) FROM timetable_semesters WHERE timetable_id=? AND semester_id=?');$stmt->execute([$tid,$sem]);if(!(int)$stmt->fetchColumn())slotJson(400,['success'=>false,'error'=>'Semester does not belong to this timetable.']);
  $clearWhere.=' AND a.day_id=? AND a.department_id=? AND a.semester_id=?';array_push($clearParams,$day,$dept,$sem);
 } else {
  if(!$hour)slotJson(400,['success'=>false,'error'=>'Choose a valid timetable hour.']);
  $check=$pdo->prepare('SELECT COUNT(*) FROM hours WHERE hour_id=?');$check->execute([$hour]);if(!(int)$check->fetchColumn())slotJson(400,['success'=>false,'error'=>'The timetable hour is invalid.']);
  $clearWhere.=' AND a.hour_id=?';$clearParams[]=$hour;
  if(!$admin){if(!$userDepartmentIds)slotJson(403,['success'=>false,'error'=>'No departments are assigned to your account.']);$clearWhere.=' AND a.department_id IN ('.implode(',',array_fill(0,count($userDepartmentIds),'?')).')';array_push($clearParams,...$userDepartmentIds);}
 }
 $scopeWhere=$clearWhere;$scopeParams=$clearParams;
 if(!$admin){$clearWhere.=' AND a.assigned_by_user_id=?';$clearParams[]=(int)$_SESSION['user']['id'];}
 try{
  $pdo->beginTransaction();
  $countStmt=$pdo->prepare('SELECT COUNT(*) FROM timetable_slot_courses a WHERE '.$scopeWhere);$countStmt->execute($scopeParams);$matchingCount=(int)$countStmt->fetchColumn();
  $stmt=$pdo->prepare('SELECT a.assignment_id,a.day_id,a.hour_id,a.department_id,a.semester_id FROM timetable_slot_courses a WHERE '.$clearWhere.' FOR UPDATE');$stmt->execute($clearParams);$cleared=$stmt->fetchAll();
  if($cleared){$ids=array_map(static fn($row)=>(int)$row['assignment_id'],$cleared);$stmt=$pdo->prepare('DELETE FROM timetable_slot_courses WHERE assignment_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$stmt->execute($ids);}
  $pdo->commit();
  slotJson(200,['success'=>true,'cleared_count'=>count($cleared),'preserved_count'=>max(0,$matchingCount-count($cleared)),'cleared'=>$cleared]);
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();slotJson(500,['success'=>false,'error'=>'Assignments could not be cleared. Please try again.']);}
}
if (!$day || !$hour || !$dept || !$sem || !in_array($action, ['add','update','delete'], true)) slotJson(400, ['success'=>false,'error'=>'The timetable slot is invalid.']);
if (!$admin && (!(int)$editing || !in_array($dept, $userDepartmentIds, true))) slotJson(403,['success'=>false,'error'=>'You are not allowed to edit this timetable slot.']);
$stmt=$pdo->prepare('SELECT COUNT(*) FROM timetable_semesters WHERE timetable_id=? AND semester_id=?');$stmt->execute([$tid,$sem]);
if (!(int)$stmt->fetchColumn()) slotJson(400,['success'=>false,'error'=>'Semester does not belong to this timetable.']);
foreach ([['days','day_id',$day],['hours','hour_id',$hour],['departments','id',$dept]] as [$table,$column,$value]) { $stmt=$pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column}=?");$stmt->execute([$value]);if (!(int)$stmt->fetchColumn()) slotJson(400,['success'=>false,'error'=>'The timetable slot is invalid.']); }
$contextStmt=$pdo->prepare('SELECT d.day_name_1,h.hour_name_1,dp.department_code,s.semester_code FROM days d JOIN hours h ON h.hour_id=? JOIN departments dp ON dp.id=? JOIN semesters s ON s.id=? WHERE d.day_id=?');
$contextStmt->execute([$hour,$dept,$sem,$day]);
$slotContext=$contextStmt->fetch();
$slotDescription=$slotContext ? $slotContext['day_name_1'].' · '.$slotContext['hour_name_1'].' · '.$slotContext['department_code'].' · '.$slotContext['semester_code'] : 'Timetable '.$tid.' slot';
try {
 if ($action==='add' || $action==='update') {
  $course=filter_input(INPUT_POST,'course_id',FILTER_VALIDATE_INT)?:0;
  $batchInput=$_POST['batch_id']??'';
  $batch=($batchInput===''||$batchInput==='none')?null:(filter_var($batchInput,FILTER_VALIDATE_INT)?:0);
  $facultyInput=$_POST['faculty_ids']??[];
  if(!is_array($facultyInput)) slotJson(400,['success'=>false,'error'=>'Select valid faculty members.']);
  $facultyIds=[];
  foreach($facultyInput as $facultyValue){$facultyId=filter_var($facultyValue,FILTER_VALIDATE_INT);if($facultyId===false||$facultyId<1)slotJson(400,['success'=>false,'error'=>'Select valid faculty members.']);$facultyIds[]=(int)$facultyId;}
  $facultyIds=array_values(array_unique($facultyIds));
  if (!$course) slotJson(400,['success'=>false,'error'=>'Select a subject.']);
  if ($batch===0) slotJson(400,['success'=>false,'error'=>'Select an existing batch or No batch.']);
  $stmt=$pdo->prepare('SELECT COUNT(*) FROM courses WHERE id=? AND department_id=? AND semester_id=?');$stmt->execute([$course,$dept,$sem]);
  if (!(int)$stmt->fetchColumn()) slotJson(400,['success'=>false,'error'=>'Choose a subject belonging to this department and semester.']);
  if ($batch!==null) {$stmt=$pdo->prepare('SELECT COUNT(*) FROM batches WHERE id=?');$stmt->execute([$batch]);if (!(int)$stmt->fetchColumn()) slotJson(400,['success'=>false,'error'=>'Choose an existing batch.']);}
  if($facultyIds){$placeholders=implode(',',array_fill(0,count($facultyIds),'?'));$stmt=$pdo->prepare("SELECT COUNT(*) FROM faculty WHERE id IN ($placeholders)");$stmt->execute($facultyIds);if((int)$stmt->fetchColumn()!==count($facultyIds))slotJson(400,['success'=>false,'error'=>'Choose existing faculty members.']);}
  $excludeAssignmentId=null;
  if($action==='update'){
   $excludeAssignmentId=filter_input(INPUT_POST,'assignment_id',FILTER_VALIDATE_INT)?:0;
   if(!$excludeAssignmentId)slotJson(400,['success'=>false,'error'=>'Choose a valid assignment to edit.']);
   $ownerStmt=$pdo->prepare('SELECT assigned_by_user_id FROM timetable_slot_courses WHERE assignment_id=? AND timetable_id=? AND day_id=? AND hour_id=? AND department_id=? AND semester_id=?');$ownerStmt->execute([$excludeAssignmentId,$tid,$day,$hour,$dept,$sem]);$owner=$ownerStmt->fetchColumn();
   if($owner===false)slotJson(404,['success'=>false,'error'=>'The slot assignment was not found.']);
   if(!$admin && (int)$owner !== (int)($_SESSION['user']['id']??0))slotJson(403,['success'=>false,'error'=>'You can edit only subjects you assigned.']);
  }
  $duplicateSql='SELECT COUNT(*) FROM timetable_slot_courses WHERE timetable_id=? AND day_id=? AND hour_id=? AND department_id=? AND semester_id=? AND course_id=? AND batch_id <=> ?';
  $duplicateParams=[$tid,$day,$hour,$dept,$sem,$course,$batch];
  if($excludeAssignmentId){$duplicateSql.=' AND assignment_id<>?';$duplicateParams[]=$excludeAssignmentId;}
  $stmt=$pdo->prepare($duplicateSql);$stmt->execute($duplicateParams);
  if ((int)$stmt->fetchColumn()) slotJson(409,['success'=>false,'error'=>'This subject and batch are already assigned to this slot.']);
  $pdo->beginTransaction();
  if($facultyIds){
   $placeholders=implode(',',array_fill(0,count($facultyIds),'?'));
   $lock=$pdo->prepare("SELECT id FROM faculty WHERE id IN ($placeholders) ORDER BY id FOR UPDATE");$lock->execute($facultyIds);
   $clashSql="SELECT f.faculty_name,c.course_code FROM timetable_slot_course_faculty tscf JOIN timetable_slot_courses a ON a.assignment_id=tscf.assignment_id JOIN faculty f ON f.id=tscf.faculty_id JOIN courses c ON c.id=a.course_id WHERE tscf.faculty_id IN ($placeholders) AND a.timetable_id=? AND a.day_id=? AND a.hour_id=?";
   $clashParams=array_merge($facultyIds,[$tid,$day,$hour]);
   if($excludeAssignmentId){$clashSql.=' AND a.assignment_id<>?';$clashParams[]=$excludeAssignmentId;}
   $clash=$pdo->prepare($clashSql.' ORDER BY f.faculty_name LIMIT 1');$clash->execute($clashParams);$conflict=$clash->fetch();
   if($conflict){$pdo->rollBack();slotJson(409,['success'=>false,'error'=>$conflict['faculty_name'].' is already assigned to '.$conflict['course_code'].' during this hour.']);}
  }
  if($action==='add'){
   $assignedById=(int)($_SESSION['user']['id']??0);$assignedByName=trim((string)($_SESSION['user']['display_name']??$_SESSION['user']['username']??''));
   $stmt=$pdo->prepare('INSERT INTO timetable_slot_courses (timetable_id,day_id,hour_id,department_id,semester_id,course_id,batch_id,assigned_by_user_id,assigned_by_name) VALUES (?,?,?,?,?,?,?,?,?)');$stmt->execute([$tid,$day,$hour,$dept,$sem,$course,$batch,$assignedById,$assignedByName]);
   $assignmentId=(int)$pdo->lastInsertId();
  } else {
   $assignmentId=$excludeAssignmentId;
   $stmt=$pdo->prepare('UPDATE timetable_slot_courses SET course_id=?,batch_id=? WHERE assignment_id=? AND timetable_id=?');$stmt->execute([$course,$batch,$assignmentId,$tid]);
   $pdo->prepare('DELETE FROM timetable_slot_course_faculty WHERE assignment_id=?')->execute([$assignmentId]);
  }
  if($facultyIds){$link=$pdo->prepare('INSERT INTO timetable_slot_course_faculty (assignment_id,faculty_id) VALUES (?,?)');foreach($facultyIds as $facultyId)$link->execute([$assignmentId,$facultyId]);}
  $pdo->commit();
  $courseInfoStmt=$pdo->prepare('SELECT course_code,course_name FROM courses WHERE id=?');$courseInfoStmt->execute([$course]);$courseInfo=$courseInfoStmt->fetch();
  $batchDescription='No batch';
  if($batch!==null){$batchInfoStmt=$pdo->prepare('SELECT batch_name1,batch_name2 FROM batches WHERE id=?');$batchInfoStmt->execute([$batch]);$batchInfo=$batchInfoStmt->fetch();if($batchInfo)$batchDescription=$batchInfo['batch_name1'].' · '.$batchInfo['batch_name2'];}
  $assignmentVerb=$action==='add'?'Added':'Edited';
  logUserActivity($action==='add'?'course_assignment_add':'course_assignment_edit','Course assignment '.$action, $assignmentVerb.' '.($courseInfo['course_code']??('course '.$course)).' ('.($courseInfo['course_name']??'').') in '.$slotDescription.'; batch: '.$batchDescription.'; faculty count: '.count($facultyIds).'.','timetable_slot',$tid.':'.$day.':'.$hour.':'.$dept.':'.$sem);
 } else {
  $aid=filter_input(INPUT_POST,'assignment_id',FILTER_VALIDATE_INT)?:0;
  if (!$aid) slotJson(400,['success'=>false,'error'=>'Select a valid assignment to remove.']);
  $ownerStmt=$pdo->prepare('SELECT assigned_by_user_id FROM timetable_slot_courses WHERE assignment_id=? AND timetable_id=? AND day_id=? AND hour_id=? AND department_id=? AND semester_id=?');$ownerStmt->execute([$aid,$tid,$day,$hour,$dept,$sem]);$owner=$ownerStmt->fetchColumn();
  if($owner===false) slotJson(404,['success'=>false,'error'=>'The slot assignment was not found.']);
  if(!$admin && (int)$owner !== (int)($_SESSION['user']['id']??0)) slotJson(403,['success'=>false,'error'=>'You can remove only subjects you assigned.']);
  $removedInfoStmt=$pdo->prepare('SELECT c.course_code,c.course_name,b.batch_name1,b.batch_name2 FROM timetable_slot_courses a JOIN courses c ON c.id=a.course_id LEFT JOIN batches b ON b.id=a.batch_id WHERE a.assignment_id=?');$removedInfoStmt->execute([$aid]);$removedInfo=$removedInfoStmt->fetch();
  $stmt=$pdo->prepare('DELETE FROM timetable_slot_courses WHERE assignment_id=? AND timetable_id=? AND day_id=? AND hour_id=? AND department_id=? AND semester_id=?');$stmt->execute([$aid,$tid,$day,$hour,$dept,$sem]);
  if (!$stmt->rowCount()) slotJson(404,['success'=>false,'error'=>'The slot assignment was not found.']);
  logUserActivity('course_assignment_remove','Course assignment removed','Removed '.($removedInfo['course_code']??'course').' ('.($removedInfo['course_name']??'').') from '.$slotDescription.(!empty($removedInfo['batch_name1'])?'; batch: '.$removedInfo['batch_name1'].' · '.$removedInfo['batch_name2']: '; no batch').'.','timetable_slot',$tid.':'.$day.':'.$hour.':'.$dept.':'.$sem);
 }
 $stmt=$pdo->prepare("SELECT a.assignment_id,a.course_id,a.batch_id,a.assigned_by_user_id,a.assigned_by_name,COALESCE(NULLIF(c.course_short_name,''),c.course_code) AS short_name,c.course_code,c.course_name,b.batch_name1,b.batch_name2,GROUP_CONCAT(DISTINCT f.faculty_short_name ORDER BY COALESCE(g.faculty_priority,100),f.faculty_name SEPARATOR ', ') AS faculty_names,GROUP_CONCAT(DISTINCT tscf.faculty_id ORDER BY COALESCE(g.faculty_priority,100),f.faculty_name SEPARATOR ',') AS faculty_ids FROM timetable_slot_courses a JOIN courses c ON c.id=a.course_id LEFT JOIN batches b ON b.id=a.batch_id LEFT JOIN timetable_slot_course_faculty tscf ON tscf.assignment_id=a.assignment_id LEFT JOIN faculty f ON f.id=tscf.faculty_id LEFT JOIN designations g ON g.id=f.designation_id WHERE a.timetable_id=? AND a.day_id=? AND a.hour_id=? AND a.department_id=? AND a.semester_id=? GROUP BY a.assignment_id,a.course_id,a.batch_id,a.assigned_by_user_id,a.assigned_by_name,c.course_short_name,c.course_code,c.course_name,b.batch_name1,b.batch_name2 ORDER BY (b.batch_name1 IS NULL),b.batch_name1,c.course_name,c.course_code,a.assignment_id");
 $stmt->execute([$tid,$day,$hour,$dept,$sem]);$rows=$stmt->fetchAll();
 foreach($rows as &$row){$row['assignment_id']=(int)$row['assignment_id'];$row['course_id']=(int)$row['course_id'];$row['batch_id']=$row['batch_id']===null?null:(int)$row['batch_id'];$row['assigned_by_user_id']=$row['assigned_by_user_id']===null?null:(int)$row['assigned_by_user_id'];}unset($row);
 slotJson(200,['success'=>true,'assignments'=>$rows]);
} catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); slotJson(500,['success'=>false,'error'=>'The subject assignment could not be saved. Please try again.']); }
