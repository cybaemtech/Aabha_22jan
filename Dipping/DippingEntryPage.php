<?php  
ob_start();
include '../Includes/db_connect.php'; 
include '../Includes/sidebar.php';  
$editData = [];
if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    $result = $conn->query("SELECT * FROM dipping_entries WHERE id = $edit_id");
    if ($result && $result->num_rows > 0) {
        $editData = $result->fetch_assoc();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>

  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Dipping Entry Page</title>
  <style>
    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      font-family: Arial, sans-serif;
      background-color: #f2f2f2;
    }

  .wrapper {
  display: flex;
}

.container {
  margin: 40px 30px 40px 280px; /* add left margin equal to sidebar width + gap */
  padding: 30px;
  background-color: #fff;
  max-width: calc(100% - 300px); /* leave space for sidebar */
  border-radius: 10px;
  box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
  animation: fadeSlideIn 0.6s ease-out;
}

    h1 {
      text-align: center;
      color: #333;
      margin-bottom: 30px;
    }

    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }

    .form-grid label {
      font-weight: 500;
      margin-bottom: 5px;
      display: block;
    }

    .form-grid input,
    .form-grid select {
      width: 100%;
      padding: 8px 12px;
      border: 1px solid #ccc;
      border-radius: 6px;
      transition: all 0.3s ease;
    }

    .form-grid input:focus,
    .form-grid select:focus {
      border-color: #3498db;
      box-shadow: 0 0 6px rgba(52, 152, 219, 0.3);
      outline: none;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 20px;
      animation: fadeSlideIn 0.8s ease-out;
    }

    table thead {
      background-color: #f7f7f7;
    }

    table th,
    table td {
      border: 1px solid #ccc;
      padding: 8px 10px;
      text-align: center;
      font-size: 14px;
    }

    table input,
    table select {
      width: 100%;
      padding: 5px;
      border: 1px solid #ccc;
      border-radius: 4px;
    }

    button {
      padding: 10px 20px;
      margin-right: 10px;
      background-color: #3498db;
      color: white;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      transition: 0.3s;
    }

    button:hover {
      background-color: #2c80b4;
    }

    .deleteRow {
      background-color: #e74c3c;
    }

    .deleteRow:hover {
      background-color: #c0392b;
    }

    @keyframes fadeSlideIn {
      from {
        opacity: 0;
        transform: translateY(30px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
  </style>
</head>
<body>
<div class="wrapper">
  <div class="container">
    <h1>Dipping Entry Page</h1>
    <form id="dippingForm" method="POST" action="">
      <div class="form-grid">
        <div>
          <label for="createdDate">Created Date:</label>
          <input type="date" id="createdDate" name="createdDate" required>
        </div>
        <div>
          <label for="shift">Shift:</label>
          <select id="shift" name="shift" required>
            <option value="">Select</option>
            <option value="morning" <?= (isset($editData['shift']) && $editData['shift'] == 'morning') ? 'selected' : '' ?>>Morning</option>
            <option value="evening" <?= (isset($editData['shift']) && $editData['shift'] == 'evening') ? 'selected' : '' ?>>Evening</option>
          </select>
        </div>
        <div>
          <label for="machineName">Machine Name:</label>
          <select id="machineName" name="machineName" required>
            <option value="">Select</option>
            <option value="machine1">Machine 1</option>
            <option value="machine2">Machine 2</option>
          </select>
        </div>
      </div>
<div>
      <button type="button" id="addRow">Add Row</button>
</div>
      <table>
        <thead>
        <tr>
          <th>Delete</th>
          <th>Sr. No.</th>
          <th>Product ID</th>
          <th>Lot No</th>
          <th>Product Type</th>
          <th>Description</th>
          <th>Bin No</th>
          <th>Weight (KG)</th>
          <th>Avg Weight (G)</th>
          <th>Gross Qty</th>
          <th>Operator</th>
        </tr>
        </thead>
        <tbody id="productRows">
        <tr>
          <td><button type="button" class="deleteRow">Delete</button></td>
          <td>1</td>
          <td><input type="text" name="productID[]" required></td>
          <td><input type="text" name="lotNo[]" required></td>
          <td>
            <select name="productType[]" required>
              <option value="">Select</option>
              <option value="type1">Type 1</option>
              <option value="type2">Type 2</option>
            </select>
          </td>
          <td><input type="text" name="productDescription[]" required></td>
          <td><input type="text" name="binNo[]" required></td>
          <td><input type="number" name="weight[]" step="0.01" required></td>
          <td><input type="number" name="averageWeight[]" step="0.01" required></td>
          <td><input type="number" name="grossQty[]" required></td>
          <td>
            <select name="operator[]" required>
              <option value="">Select</option>
              <option value="operator1">Operator 1</option>
              <option value="operator2">Operator 2</option>
            </select>
          </td>
        </tr>
        </tbody>
      </table>

      <button type="submit">Save</button>
      <button type="reset">Cancel</button>
    </form>
  </div>
</div>

<script>
  document.getElementById("addRow").addEventListener("click", function () {
    const tableBody = document.getElementById("productRows");
    const rowCount = tableBody.rows.length + 1;
    const row = document.createElement("tr");
    row.innerHTML = `
      <td><button type="button" class="deleteRow">Delete</button></td>
      <td>${rowCount}</td>
      <td><input type="text" name="productID[]" required></td>
      <td><input type="text" name="lotNo[]" required></td>
      <td>
        <select name="productType[]" required>
          <option value="">Select</option>
          <option value="type1">Type 1</option>
          <option value="type2">Type 2</option>
        </select>
      </td>
      <td><input type="text" name="productDescription[]" required></td>
      <td><input type="text" name="binNo[]" required></td>
      <td><input type="number" name="weight[]" step="0.01" required></td>
      <td><input type="number" name="averageWeight[]" step="0.01" required></td>
      <td><input type="number" name="grossQty[]" required></td>
      <td>
        <select name="operator[]" required>
          <option value="">Select</option>
          <option value="operator1">Operator 1</option>
          <option value="operator2">Operator 2</option>
        </select>
      </td>
    `;
    tableBody.appendChild(row);
  });

  document.addEventListener("click", function (e) {
    if (e.target && e.target.classList.contains("deleteRow")) {
      e.target.closest("tr").remove();
    }
  });

  document.getElementById('dippingForm').addEventListener('reset', function() {
      document.getElementById('shift').disabled = false;
  });
</script>
<?php
$editData = [];
if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    $result = $conn->query("SELECT * FROM dipping_entries WHERE id = $edit_id");
    if ($result && $result->num_rows > 0) {
        $editData = $result->fetch_assoc();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shift = $_POST['shift'];
    // ...other fields...
    if (isset($_POST['edit_id'])) {
        $edit_id = intval($_POST['edit_id']);
        $stmt = $conn->prepare("UPDATE dipping_entries SET shift=? WHERE id=?");
        $stmt->bind_param("si", $shift, $edit_id);
        $stmt->execute();
        // ...redirect or show success...
    }
    // ...insert logic...
}
?>
</body>
</html>
