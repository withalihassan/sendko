<div class="container mt-3">
        <div class="row">
            <div class="col-6 col-md-2 mb-3">
                <div class="card text-center bg-info text-white">
                    <div class="card-body py-2">
                        <?php $stmt = $pdo->query("SELECT COUNT(*) AS count FROM accounts WHERE DATE(added_date)=CURDATE() AND by_user=$user_id"); ?>
                        <h6 class="card-title">Added Today</h6>
                        <p class="card-text"><?php echo $stmt->fetch(PDO::FETCH_ASSOC)['count']; ?></p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2 mb-3">
                <div class="card text-center bg-success text-white">
                    <div class="card-body py-2">
                        <?php $stmt = $pdo->query("SELECT COUNT(*) AS count FROM accounts WHERE status='active' AND by_user='$user_id' "); ?>
                        <h6 class="card-title">Total Active</h6>
                        <p class="card-text"><?php echo $stmt->fetch(PDO::FETCH_ASSOC)['count']; ?></p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2 mb-3">
                <div class="card text-center bg-secondary text-white">
                    <div class="card-body py-2">
                        <?php $stmt = $pdo->query("SELECT COUNT(*) AS count FROM accounts WHERE ac_state='orphan' AND by_user='$user_id' "); ?>
                        <h6 class="card-title">Claimable</h6>
                        <p class="card-text"><?php echo $stmt->fetch(PDO::FETCH_ASSOC)['count']; ?></p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2 mb-3">
                <div class="card text-center bg-primary text-white">
                    <div class="card-body py-2">
                        <?php $stmt = $pdo->query("SELECT COUNT(*) AS count FROM accounts WHERE ac_state='claimed' AND by_user='$user_id' "); ?>
                        <h6 class="card-title">Total Claimed</h6>
                        <p class="card-text"><?php echo $stmt->fetch(PDO::FETCH_ASSOC)['count']; ?></p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2 mb-3">
                <div class="card text-center bg-danger text-white">
                    <div class="card-body py-2">
                        <?php $stmt = $pdo->query("SELECT COUNT(*) AS count FROM accounts WHERE status='suspended' AND by_user='$user_id' "); ?>
                        <h6 class="card-title">Total Susp.</h6>
                        <p class="card-text"><?php echo $stmt->fetch(PDO::FETCH_ASSOC)['count']; ?></p>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2 mb-3">
                <div class="card text-center bg-danger text-white">
                    <div class="card-body py-2">
                        <?php
                        $stmt_rejected = $pdo->query("SELECT COUNT(*) AS count FROM accounts WHERE ac_state = 'rejected' AND by_user = '$user_id'");
                        $rejected_count = $stmt_rejected->fetch(PDO::FETCH_ASSOC)['count'];
                        ?>
                        <h6 class="card-title">Total Rejected</h6>
                        <p class="card-text"><?php echo $rejected_count; ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>