<?php
// filepath: c:\xampp\htdocs\Aabha\Includes\access_denied.php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Access Denied</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
</head>
<body>
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card text-center">
                <div class="card-body">
                    <i class="fas fa-ban fa-5x text-danger mb-3"></i>
                    <h2 class="text-danger">Access Denied</h2>
                    <p class="mb-3">You don't have permission to access this page.</p>
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-home"></i> Back to Home
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>