<?php
// region_patch_api.php

include('db.php');                     // $pdo
require_once __DIR__.'/aws/aws-autoloader.php';
use Aws\Sns\SnsClient, Aws\Exception\AwsException;
use Aws\Account\AccountClient;

// -------------- Helper Functions --------------

function initSNS($key,$secret,$region){
  try{
    return new SnsClient([
      'version'=>'latest','region'=>$region,
      'credentials'=>['key'=>$key,'secret'=>$secret]
    ]);
  }catch(Exception $e){
    return ['error'=>$e->getMessage()];
  }
}

function fetch_numbers($region,$user_id,$pdo,$set_id){
  if(!$region) return ['error'=>'Region required'];
  $sql="SELECT id,phone_number,atm_left,DATE_FORMAT(created_at,'%Y-%m-%d') as formatted_date
        FROM allowed_numbers WHERE status='fresh' AND atm_left>0";
  $params=[];
  if($set_id){
    $sql.=" AND set_id=?";
    $params[]=$set_id;
  }
  $sql.=" ORDER BY RAND() LIMIT 50";
  $stmt=$pdo->prepare($sql);
  $stmt->execute($params);
  return ['success'=>true,'region'=>$region,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)];
}

function send_otp_single($id,$phone,$region,$key,$secret,$user_id,$pdo,$sns,$language){
  if(!$id||!$phone) return ['status'=>'error','message'=>'Invalid','region'=>$region];
  // check atm, decrement, update...
  $stmt=$pdo->prepare("SELECT atm_left FROM allowed_numbers WHERE id=?");
  $stmt->execute([$id]);
  $num=$stmt->fetch(PDO::FETCH_ASSOC);
  if(!$num||$num['atm_left']<=0) return ['status'=>'error','message'=>'No ATM left','region'=>$region];

  $langCodes=['Spanish Latin America'=>'es-419','United States'=>'en-US','Japanese'=>'ja-JP','German'=>'de-DE'];
  $awsLang=$langCodes[$language]??'es-419';
  try{
    $sns->createSMSSandboxPhoneNumber(['PhoneNumber'=>$phone,'LanguageCode'=>$awsLang]);
  }catch(AwsException $e){
    $err=$e->getAwsErrorMessage();
    if(strpos($err,"MONTHLY_SPEND_LIMIT")!==false)
      return ['status'=>'skip','message'=>'Monthly limit reached','region'=>$region];
    return ['status'=>'error','message'=>$err,'region'=>$region];
  }
  // update ATM count
  $new=$num['atm_left']-1;
  $status= $new? 'fresh':'used';
  $pdo->prepare("UPDATE allowed_numbers SET atm_left=?,last_used=NOW(),status=? WHERE id=?")
      ->execute([$new,date('Y-m-d H:i:s'),$status,$id]);
  return ['status'=>'success','message'=>"OTP sent to $phone",'region'=>$region];
}

function list_regions($key,$secret){
  try{
    $cli=new AccountClient(['version'=>'latest','region'=>'us-east-1','credentials'=>['key'=>$key,'secret'=>$secret]]);
    $res=$cli->listRegions([]);
    return ['status'=>'success','regions'=>$res['Regions']];
  }catch(Exception $e){
    return ['status'=>'error','message'=>$e->getMessage()];
  }
}

function change_region_opt($key,$secret,$region,$action){
  try{
    $cli=new AccountClient(['version'=>'latest','region'=>'us-east-1','credentials'=>['key'=>$key,'secret'=>$secret]]);
    if($action==='enable') $cli->enableRegion(['RegionName'=>$region]);
    else                  $cli->disableRegion(['RegionName'=>$region]);
    $status=$cli->getRegionOptStatus(['RegionName'=>$region])['RegionOptStatus']??'UNKNOWN';
    return ['status'=>'success','message'=>ucfirst($action)." submitted for $region. Status: $status."];
  }catch(AwsException $e){
    return ['status'=>'error','message'=>$e->getAwsErrorMessage()];
  }
}

function update_account($id,$pdo){
  date_default_timezone_set('Asia/Karachi');
  $now=date('Y-m-d H:i:s');
  try{
    $pdo->prepare("UPDATE accounts SET ac_score=ac_score+1,last_used=:t WHERE id=:i")
        ->execute([':i'=>$id,':t'=>$now]);
    return ['success'=>true,'message'=>'Account updated','time'=>$now];
  }catch(PDOException $e){
    return ['success'=>false,'message'=>$e->getMessage()];
  }
}

// -------------- API Dispatcher --------------

header('Content-Type: application/json');

$act = $_REQUEST['action'] ?? '';
switch($act){
  case 'list_regions':
    echo json_encode(list_regions($_POST['awsKey'],$_POST['awsSecret']));
    break;
  case 'region_opt':
    echo json_encode(change_region_opt($_POST['awsKey'],$_POST['awsSecret'],$_POST['region'],$_POST['action']));
    break;
  case 'fetch_numbers':
    $res=fetch_numbers($_POST['region'],intval($_POST['user_id']),$pdo,intval($_POST['set_id']));
    echo json_encode($res['error']? ['status'=>'error','message'=>$res['error']] : ['status'=>'success','data'=>$res['data']]);
    break;
  case 'send_otp_single':
    $json=send_otp_single(intval($_POST['id']),$_POST['phone'],$_POST['region'],
                          $_POST['awsKey'],$_POST['awsSecret'],intval($_POST['user_id']),$pdo,
                          initSNS($_POST['awsKey'],$_POST['awsSecret'],$_POST['region']),
                          $_POST['language']);
    echo json_encode($json);
    break;
  case 'update_account':
    echo json_encode(update_account(intval($_POST['ac_id']),$pdo));
    break;
  default:
    echo json_encode(['status'=>'error','message'=>'Invalid action']);
}
