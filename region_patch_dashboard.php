<?php
// region_patch_dashboard.php

include('db.php');                     // your PDO $pdo
require_once __DIR__.'/aws/aws-autoloader.php';
include('region_patch_api.php');       // pull in all helper functions

// -- ensure valid account & user IDs
if (!isset($_GET['ac_id']) || !isset($_GET['user_id'])) {
  echo "Account ID and User ID required."; exit;
}
$id      = intval($_GET['ac_id']);
$user_id = intval($_GET['user_id']);

// fetch AWS creds
$stmt = $pdo->prepare("SELECT aws_key, aws_secret FROM accounts WHERE id = ?");
$stmt->execute([$id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$account)   { echo "Account not found."; exit; }
$aws_key    = htmlspecialchars($account['aws_key']);
$aws_secret = htmlspecialchars($account['aws_secret']);

// -- SSE bulk‐patch loop (exactly as before, using sendSSE, initSNS, fetch_numbers, send_otp_single)
if (isset($_GET['stream'])) {
  // validate set_id...
  $set_id = intval($_GET['set_id'] ?? 0);
  if (!$set_id) { echo "No set selected."; exit; }
  $language       = trim($_GET['language'] ?? 'Spanish Latin America');
  $selectedRegion = trim($_GET['region'] ?? '');
  // clear any stop flags
  $stopFile = "stop_$id.txt";
  if (file_exists($stopFile)) unlink($stopFile);

  header('Content-Type: text/event-stream');
  header('Cache-Control: no-cache');
  while (ob_get_level()) ob_end_flush();
  set_time_limit(0);
  ignore_user_abort(true);

  function sendSSE($type, $msg) {
    echo "data:$type|" . str_replace("\n","\\n",$msg) . "\n\n"; flush();
  }
  sendSSE("STATUS","Starting Bulk Regional Patch for Set $set_id (Lang: $language)");

  $regions = $selectedRegion
           ? [ $selectedRegion ]
           : [ "us-east-1","us-east-2","us-west-1","us-west-2","ap-south-1",
               "ap-northeast-3","ap-southeast-1","ap-southeast-2","ap-northeast-1",
               "ca-central-1","eu-central-1","eu-west-1","eu-west-2","eu-west-3",
               "eu-north-1","me-central-1","sa-east-1","af-south-1","ap-southeast-3",
               "ap-southeast-4","ca-west-1","eu-south-1","eu-south-2","eu-central-2",
               "me-south-1","il-central-1","ap-south-2"
             ];
  $totalRegions = count($regions);
  $totalSuccess = $usedRegions = 0;

  foreach ($regions as $region) {
    if (file_exists($stopFile)) {
      sendSSE("STATUS","Process stopped by user."); unlink($stopFile); exit;
    }
    $usedRegions++;
    sendSSE("STATUS","Moving to region: $region");
    sendSSE("COUNTERS","Total Patch: $totalSuccess; Region $region; Processed $usedRegions of $totalRegions");

    $fetch = fetch_numbers($region,$user_id,$pdo,$set_id);
    if (isset($fetch['error'])) {
      sendSSE("STATUS","Error fetching for $region: ".$fetch['error']);
      sleep(5); continue;
    }
    $allowed = $fetch['data'];
    if (empty($allowed)) {
      sendSSE("STATUS","No numbers in $region"); sleep(5); continue;
    }
    // build otpTasks (first 5 normal, 6th twice if >=6)
    $otpTasks = [];
    if (count($allowed)>=6) {
      for ($i=0;$i<5;$i++)
        $otpTasks[] = ['id'=>$allowed[$i]['id'],'phone'=>$allowed[$i]['phone_number']];
      $otpTasks[]=$otpTasks[]=['id'=>$allowed[5]['id'],'phone'=>$allowed[5]['phone_number']];
    } else {
      foreach($allowed as $n) $otpTasks[]=['id'=>$n['id'],'phone'=>$n['phone_number']];
    }

    $otpSentInThisRegion = $verifDestError = false;
    foreach ($otpTasks as $task) {
      if (file_exists($stopFile)) {
        sendSSE("STATUS","Process stopped by user."); unlink($stopFile); exit;
      }
      sendSSE("STATUS","[$region] Sending Patch…");
      $sns = initSNS($aws_key,$aws_secret,$region);
      if (isset($sns['error'])) {
        sendSSE("ROW","{$task['id']}|{$task['phone']}|$region|Patch Failed: ".$sns['error']);
        continue;
      }
      $res = send_otp_single($task['id'],$task['phone'],$region,$aws_key,$aws_secret,$user_id,$pdo,$sns,$language);
      if ($res['status']==='success') {
        sendSSE("ROW","{$task['id']}|{$task['phone']}|$region|Patch Sent");
        $totalSuccess++; $otpSentInThisRegion=true;
        sendSSE("COUNTERS","Total Patch: $totalSuccess; Region $region; Processed $usedRegions of $totalRegions");
        sleep(2);
      }
      else if ($res['status']==='skip') {
        sendSSE("ROW","{$task['id']}|{$task['phone']}|$region|Skipped: {$res['message']}");
      }
      else {
        sendSSE("ROW","{$task['id']}|{$task['phone']}|$region|Failed: {$res['message']}");
        if (strpos($res['message'],"VERIFIED_DESTINATION_NUMBERS_PER_ACCOUNT")!==false) {
          $verifDestError=true;
          sendSSE("STATUS","[$region] VERIFIED_DESTINATION… skipping region."); break;
        }
        elseif (strpos($res['message'],"Access Denied")!==false||strpos($res['message'],"Region Restricted")!==false) {
          sendSSE("STATUS","[$region] Critical error… skipping region."); break;
        }
        else { sleep(5); }
      }
    }
    if ($verifDestError) { sleep(5); }
    else if ($otpSentInThisRegion) { sendSSE("STATUS","Done $region, waiting 20s…"); sleep(20); }
    else { sendSSE("STATUS","Done $region, waiting 5s…"); sleep(5); }
  }

  $summary = "Final Summary:<br>Total Patch sent: $totalSuccess<br>Regions processed: $usedRegions<br>Remaining: ".($totalRegions-$usedRegions);
  sendSSE("SUMMARY",$summary);
  sendSSE("STATUS","Process Completed.");
  exit;
}

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= $id ?> | Region & Bulk‑Patch Dashboard</title>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <style>
    body { font-family: Arial; background: #f7f7f7; margin:20px }
    .container { max-width:900px; margin:auto; background:#fff; padding:20px; box-shadow:0 0 10px rgba(0,0,0,0.1); border-radius:5px }
    h1,h2 { text-align:center; color:#333 }
    .box { border:1px solid #ccc; padding:15px; margin-bottom:20px; border-radius:5px; }
    .box.small { max-width:300px; }
    label, select, button, input, textarea { width:100%; padding:8px; margin:5px 0; }
    button { background:#007bff; color:#fff; border:none; cursor:pointer; }
    button.danger { background:#dc3545 }
    table { width:100%; border-collapse:collapse; margin-top:10px }
    th,td { border:1px solid #ccc; padding:8px; text-align:center }
    th { background:#f4f4f4 }
    #counters { background:#eee; padding:5px; font-weight:bold; text-align:center; }
    .message { padding:10px; display:none; margin:10px 0; border-radius:5px }
    .message.error { background:#f8d7da; color:#721c24 }
    .message.success { background:#d4edda; color:#155724 }
  </style>
</head>
<body>
  <div class="container">
    <h1>Region & Bulk Patch Dashboard</h1>

    <!-- small box: region enable/disable -->
    <div class="box small">
      <h2>Manage Regions</h2>
      <p><strong>AWS Key:</strong> <?= $aws_key ?></p>
      <p><strong>AWS Secret:</strong> <?= $aws_secret ?></p>
      <div id="region-totals"></div>
      <button id="toggle-regions-btn">Show/Hide Disabled Regions</button>
      <div id="region-opt-div" style="display:none;">
        <select id="region-select"></select>
        <button id="enable-btn">Enable</button>
        <button id="disable-btn" class="danger">Disable</button>
      </div>
      <div>
        <button id="enable-set1">Enable Set 1</button>
        <button id="enable-set2">Enable Set 2</button>
      </div>
      <div id="response-message"></div>
    </div>

    <!-- large box: bulk patch process -->
    <div class="box large">
      <h2>Bulk Regional Patch Sending</h2>
      <div style="display:flex; gap:10px; margin-bottom:10px;">
        <button id="updateButton">Mark as Completed</button>
        <button id="stopButton" class="danger">Stop Process</button>
      </div>
      <form id="bulk-form">
        <div>
          <label for="set_id">Select Set:</label>
          <select id="set_id" name="set_id">
            <option value="">-- Select a Set --</option>
            <?php
              $sets = $pdo->query("SELECT id,set_name FROM bulk_sets ORDER BY set_name")->fetchAll();
              foreach($sets as $s) echo "<option value=\"{$s['id']}\">".htmlspecialchars($s['set_name'])."</option>";
            ?>
          </select>
        </div>
        <div>
          <label for="region_select">Region (or All):</label>
          <select id="region_select"><option value="">All Regions</option>
            <?php foreach (["us‑east‑1","us‑west‑2","ap‑south‑1",/* etc... */] as $r) echo "<option>$r</option>"; ?>
          </select>
        </div>
        <div>
          <label for="lang_select">Language:</label>
          <select id="lang_select">
            <option>Spanish Latin America</option>
            <option>United States</option>
            <option>Japanese</option>
            <option>German</option>
          </select>
        </div>
        <p><strong>AWS Key:</strong> <?= $aws_key ?> &nbsp; <strong>AWS Secret:</strong> <?= $aws_secret ?></p>
        <button type="button" id="start-bulk-regional-otp">Start Bulk Patch</button>
      </form>
      <label>Allowed Phone Numbers:</label>
      <textarea id="numbers" rows="6" readonly></textarea>
      <div id="process-status" class="message"></div>
      <h2>Live Counters</h2><div id="counters"></div>
      <h2>OTP Events</h2>
      <table id="sent-numbers-table">
        <thead><tr><th>ID</th><th>Phone</th><th>Region</th><th>Status</th></tr></thead>
        <tbody></tbody>
      </table>
      <h2>Final Summary</h2><div id="summary"></div>
    </div>
  </div>

  <script>
  $(function(){
    // cached IDs
    var userId=<?= $user_id ?>, acId=<?= $id ?>, api='region_patch_api.php';

    // ==== REGION MANAGEMENT ====
    function fetchRegionTotals(){
      $.post(api,{action:'list_regions',awsKey:'<?= $aws_key ?>',awsSecret:'<?= $aws_secret ?>'},function(r){
        if(r.status==='success'){
          var enabled=0,disabled=0,opts=[];
          staticRegions.forEach(function(reg){
            var found=r.regions.find(x=>x.RegionName===reg.code);
            if(found&&found.RegionOptStatus==='ENABLED') enabled++;
            else { disabled++; opts.push(reg); }
          });
          $('#region-totals').html(
            '<p>Total Enabled: '+enabled+'<br>Total Disabled: '+disabled+'</p>'
          );
          var sel=$('#region-select').empty();
          if(opts.length) opts.forEach(o=>sel.append('<option value="'+o.code+'">'+o.name+'</option>'));
          else sel.append('<option>No disabled regions</option>');
        } else {
          $('#region-totals').html('<p style="color:red">'+r.message+'</p>');
        }
      },'json');
    }
    $('#toggle-regions-btn').click(()=>$('#region-opt-div').slideToggle());
    $('#enable-btn').click(()=> regionOpt('enable') );
    $('#disable-btn').click(()=> regionOpt('disable') );
    $('#enable-set1').click(()=> bulkEnable([ 'me-central-1','sa-east-1','af-south-1','ap-southeast-3','ap-southeast-4','ca-west-1','eu-south-1' ]) );
    $('#enable-set2').click(()=> bulkEnable([ 'eu-south-2','eu-central-2','me-south-1','il-central-1','ap-south-2' ]) );

    function regionOpt(type){
      var code=$('#region-select').val(), name=$('#region-select option:selected').text();
      $.post(api,{action:'region_opt',region:code,awsKey:'<?= $aws_key ?>',awsSecret:'<?= $aws_secret ?>'},function(r){
        $('#response-message').append(
          '<p style="color:'+(r.status==='success'?'green':'red')+'">'+r.message+' for '+name+'</p>'
        );
        fetchRegionTotals();
      },'json');
    }
    function bulkEnable(list){
      $('#response-message').empty();
      list.forEach((code,i)=>setTimeout(()=> regionOpt('enable'), i*2000));
    }
    // define staticRegions for JS:
    window.staticRegions = <?= json_encode([
      ["code"=>"me-central-1","name"=>"UAE"],
      ["code"=>"sa-east-1","name"=>"Sao Paulo"],
      ["code"=>"af-south-1","name"=>"Africa"],
      ["code"=>"ap-southeast-3","name"=>"Jakarta"],
      ["code"=>"ap-southeast-4","name"=>"Melbourne"],
      ["code"=>"ca-west-1","name"=>"Calgary"],
      ["code"=>"eu-south-1","name"=>"Milan"],
      ["code"=>"eu-south-2","name"=>"Spain"],
      ["code"=>"eu-central-2","name"=>"Zurich"],
      ["code"=>"me-south-1","name"=>"Bahrain"],
      ["code"=>"il-central-1","name"=>"Tel Aviv"],
      ["code"=>"ap-south-2","name"=>"Hyderabad"]
    ]) ?>;
    fetchRegionTotals();

    // ==== BULK PATCH FORM & SSE ====
    $('#set_id,#region_select').change(function(){
      var set_id=$('#set_id').val(), region=$('#region_select').val()||'dummy';
      if(!set_id) return $('#numbers').val('');
      $.post(api,{action:'fetch_numbers',region:region,set_id:set_id,user_id:userId},function(r){
        if(r.status==='success'){
          $('#numbers').val(r.data.map(i=>'ID:'+i.id+' | '+i.phone_number+' | ATM:'+i.atm_left+' | '+i.formatted_date).join('\n'));
        } else $('#numbers').val('Error: '+r.message);
      },'json');
    });

    $('#start-bulk-regional-otp').click(function(){
      var set_id=$('#set_id').val();
      if(!set_id){ alert('Select a set.'); return; }
      $(this).prop('disabled',true);
      $('#process-status,#summary,#counters,#sent-numbers-table tbody').empty();
      var lang=encodeURIComponent($('#lang_select').val()), region=$('#region_select').val(), sse='region_patch_dashboard.php?ac_id='+acId+'&user_id='+userId+'&set_id='+set_id+'&stream=1&language='+lang+(region?'&region='+region:'');
      var evt=new EventSource(sse);
      evt.onmessage=function(e){
        var parts=e.data.split('|'), type=parts[0], payload=parts.slice(1).join('|').replace(/\\n/g,'\n');
        if(type==='ROW'){
          var row=payload.split('|');
          $('#sent-numbers-table tbody').append(`<tr><td>${row[0]}</td><td>${row[1]}</td><td>${row[2]}</td><td>${row[3]}</td></tr>`);
        } else if(type==='STATUS'){
          $('#process-status').text(payload).show();
        } else if(type==='COUNTERS'){
          $('#counters').html(payload);
        } else if(type==='SUMMARY'){
          $('#summary').html(payload);
        }
      };
      evt.onerror=function(){ $('#process-status').addClass('error').text('SSE error').show(); evt.close(); };
    });

    // UPDATE ACCOUNT button
    $('#updateButton').click(function(){
      $.post(api,{action:'update_account',ac_id:acId},function(r){
        $('<p>').css('color',r.success?'green':'red').text(r.message).appendTo('#process-status').show();
      },'json');
    });
    $('#stopButton').click(function(){
      $.post(location.href,{action:'stop_process'},function(){ $('#process-status').addClass('success').text('Stop requested').show(); },'json');
    });

  });
  </script>
</body>
</html>
