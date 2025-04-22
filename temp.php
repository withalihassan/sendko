<script>
    $(function(){
  const acId        = <?php echo $id; ?>;
  const userId      = <?php echo $user_id; ?>;
  const regions     = [
    "me-central-1","af-south-1",
    "ap-southeast-3","ap-southeast-4","ca-west-1",
    "eu-south-1","eu-south-2","eu-central-2",
    "me-south-1","il-central-1","ap-south-2"
  ];
  const maxConcurrent = 6;
  const delayMs       = 2000;
  const pollIntervals = {};
  let queue = [];
  let activeCount = 0;

  $('#enableRegionsButton').on('click', () => {
    const $tbody = $('#regions-status-table tbody').empty();
    queue = regions.slice();
    activeCount = 0;
    // start initial batch scheduling
    scheduleNext($tbody);
  });

  function scheduleNext($tbody) {
    // only schedule if slots and items remain
    if (activeCount < maxConcurrent && queue.length) {
      setTimeout(() => {
        const region = queue.shift();
        checkAndSubmit(region, $tbody);
        // schedule further until slots fill or queue empty
        scheduleNext($tbody);
      }, delayMs);
    }
  }

  function checkAndSubmit(region, $tbody) {
    let $row = $tbody.find(`tr[data-region="${region}"]`);
    if (!$row.length) {
      $tbody.append(
        `<tr data-region="${region}">` +
          `<td>${region}</td>` +
          `<td class="status">Checking…</td>` +
        `</tr>`
      );
      $row = $tbody.find(`tr[data-region="${region}"]`);
    }
    const $status = $row.find('.status');

    $.post(
      `region_enable_handler.php?ac_id=${acId}&user_id=${userId}`,
      { action:'check_region_status', region },
      'json'
    )
    .done(data => {
      if (data.success && data.status === 'ENABLED') {
        $status.text('Already Enabled');
        // free slot immediately (no poll consumed)
        scheduleNext($tbody);
      } else {
        $status.text('Submitted, Waiting…');
        $.post(
          `region_enable_handler.php?ac_id=${acId}&user_id=${userId}`,
          { action:'enable_region', region },
          'json'
        )
        .done(() => {
          // consume a slot for polling
          activeCount++;
          startPolling(region, $status, $tbody);
        })
        .fail(() => {
          $status.text('Enable Error');
          scheduleNext($tbody);
        });
      }
    })
    .fail(() => {
      $status.text('Check Error');
      scheduleNext($tbody);
    });
  }

  function startPolling(region, $status, $tbody) {
    if (pollIntervals[region]) clearInterval(pollIntervals[region]);
    pollIntervals[region] = setInterval(() => {
      $.post(
        `region_enable_handler.php?ac_id=${acId}&user_id=${userId}`,
        { action:'check_region_status', region },
        'json'
      )
      .done(data => {
        if (data.success && data.status === 'ENABLED') {
          clearInterval(pollIntervals[region]);
          $status.text('Enabled Successfully');
          activeCount--;
          scheduleNext($tbody);
        } else {
          $status.text(`Still Enabling…(${data.status})`);
        }
      })
      .fail(() => {
        $status.text('Poll Error');
      });
    }, 40000);
  }
});
</script>