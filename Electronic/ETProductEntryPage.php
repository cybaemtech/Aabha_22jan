<?php
ob_start();
include '../Includes/db_connect.php'; 
include '../Includes/sidebar.php';  
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ET Product Entry Page</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>

 <style>
    body {
        margin: 0;
        padding: 0;
    }

    .main-content {
        margin-left: 260px; /* leave space for sidebar */
        padding: 20px;
    }

    .form-group {
        margin-bottom: 15px;
    }

    .delete-icon {
        cursor: pointer;
        color: red;
    }

    table th,
    table td {
        vertical-align: middle;
        text-align: center;
    }

    .table input,
    .table select {
        min-width: 100px;
    }
</style>

</head>
<body>

<div class="wrapper">
  <!-- Sidebar -->


  <!-- Main Content -->
  <div class="main-content">
    <div class="container-fluid">
     <h1 class="display-5 fw-bold center">Electronic Testing Product Entry</h1>
      <form action="process_form.php" method="POST">
        <div class="form-row">
          <div class="form-group col-md-4">
            <label for="createdDate">Created Date:</label>
            <input type="date" class="form-control" id="createdDate" name="createdDate" required>
          </div>
          <div class="form-group col-md-4">
            <label for="lotNo">Lot No:</label>
            <select id="lotNo" class="form-control" name="lotNo" required>
              <option value="">Select</option>
              <!-- Add options -->
            </select>
          </div>
          <div class="form-group col-md-4">
            <label for="productDescription">Product Description:</label>
            <input type="text" class="form-control" id="productDescription" name="productDescription" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group col-md-4">
            <label for="shift">Shift:</label>
            <select id="shift" class="form-control" name="shift" required>
              <option value="">Select</option>
              <!-- Add options -->
            </select>
          </div>
        </div>

        <table class="table table-bordered">
          <thead class="thead-dark">
            <tr>
              <th>Action</th>
              <th>Sr. No.</th>
              <th>Bin No.</th>
              <th>Average Weight (GM)</th>
              <th>Pass KG</th>
              <th>Pass Gross Qty</th>
              <th>Reject Gross Qty</th>
              <th>Operator ID</th>
              <th>Machine</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td class="delete-icon"><i class="fas fa-trash-alt"></i>
            </td>
              <td>1</td>
              <td><input type="number" class="form-control" name="binNo[]" required></td>
              <td><input type="number" class="form-control" name="avgWeight[]" required></td>
              <td><input type="number" class="form-control" name="passKg[]" required></td>
              <td><input type="number" class="form-control" name="passGrossQty[]" required></td>
              <td><input type="number" class="form-control" name="rejectGrossQty[]" required></td>
              <td><input type="text" class="form-control" name="operatorID[]" required></td>
              <td>
                <select class="form-control" name="machine[]" required>
                  <option value="">Select</option>
                  <!-- Add options -->
                </select>
              </td>
            </tr>
          </tbody>
        </table>

        <div class="text-center">
          <button type="submit" class="btn btn-primary">SAVE</button>
          <button type="button" class="btn btn-secondary" onclick="window.location.href='index.php';">CANCEL</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- FontAwesome -->
<script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
</body>
</html>
