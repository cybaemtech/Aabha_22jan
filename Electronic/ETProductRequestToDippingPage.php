<?php
ob_start();
include '../Includes/db_connect.php'; 
include '../Includes/sidebar.php';  
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Product Request Entry</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>

  <style>
    body {
      background-color: #f8f9fa;
    }

    .form-section {
      background: #fff;
      padding: 20px;
      border-radius: 10px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      margin-left: 260px; /* space for sidebar */
      margin-top: 20px;
      max-width: calc(100% - 280px); /* prevent overlap */
    }

    .form-header {
      background-color: #6c757d;
      color: white;
      padding: 10px 15px;
      font-weight: bold;
      border-radius: 5px;
      margin-bottom: 20px;
      text-align: center;
    }

    table th {
      background-color: #6c757d;
      color: white;
      text-align: center;
      vertical-align: middle;
    }

    table td {
      vertical-align: middle;
      text-align: center;
    }

    .hidden-bin-field {
      display: none;
    }

    .form-control[readonly] {
      background-color: #e9ecef;
    }
  </style>
</head>
<body>
<div class="container-fluid form-section">
  <div class="form-header text-center py-3 mb-4 bg-primary text-white rounded">
    <h3 class="m-0">Product Request Entry</h3>
  </div>
  <div class="row mb-3">
    <div class="col-md-4">
      <label>Request Number:</label>
      <input type="text" class="form-control">
    </div>
    <div class="col-md-4">
      <label>LotNo:</label>
      <select class="form-select">
        <option>Select</option>
      </select>
    </div>
    <div class="col-md-4">
      <label>Product Description:</label>
      <input type="text" class="form-control">
    </div>
  </div>

  <table class="table table-bordered">
    <thead>
      <tr>
        <th>Sr. No.</th>
        <th>Add Bin</th>
        <th>Bin Available Under Entered LotNo</th>
        <th>Average WT</th>
        <th>Gross at Bin</th>
        <th>IsApprove</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>1</td>
        <td><input type="checkbox" class="form-check-input add-bin-toggle"></td>
        <td><input type="text" class="form-control bin-field" value="0" readonly></td>
        <td><input type="text" class="form-control bin-field" value="0" readonly></td>
        <td><input type="text" class="form-control bin-field" value="0" readonly></td>
        <td><input type="checkbox" class="form-check-input"></td>
      </tr>
    </tbody>
  </table>

  <div class="row mb-3">
    <div class="col-md-4">
      <label>Request By Name:</label>
      <input type="text" class="form-control" readonly>
    </div>
    <div class="col-md-4">
      <label>Issued Date:</label>
      <input type="text" class="form-control" readonly>
    </div>
    <div class="col-md-4">
      <label>Issued By Name:</label>
      <input type="text" class="form-control" readonly>
    </div>
  </div>

  <div class="text-center">
    <button class="btn btn-primary">Forward Request</button>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

</body>
</html>
