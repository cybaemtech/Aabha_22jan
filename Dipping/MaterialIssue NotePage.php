<?php
// Replace the JavaScript section with this enhanced version:
?>
<script>
    // Enhanced material data for JS with safe handling
    const materials = <?= json_encode($materials) ?>;
    const batchActualQtyMap = <?= json_encode($batchActualQtyMap ?: []) ?>;
    const materialBatchesMap = <?= json_encode($materialBatchesMap ?: []) ?>;
    const acceptedQtyMap = <?= json_encode($acceptedQtyMap ?: []) ?>;

    function onMaterialChange(select, rowIdx) {
        const selectedDesc = select.value;
        const mat = materials.find(m => m.material_description === selectedDesc);
        
        if (mat) {
            // Set material ID and unit
            document.getElementsByName('material_id[]')[rowIdx].value = mat.material_id;
            document.getElementsByName('unit[]')[rowIdx].value = mat.unit_of_measurement || '';

            // Get total accepted_qty for this material_id + description
            const key = mat.material_id + '||' + mat.material_description;
            const totalAcceptedQty = acceptedQtyMap[key] ? parseFloat(acceptedQtyMap[key]) : 0;
            document.getElementsByName('available_qty[]')[rowIdx].value = totalAcceptedQty || '';

            // Clear batch field and hide qty display
            document.getElementsByName('batch_no[]')[rowIdx].value = '';
            hideActualQtyDisplay(rowIdx);
            hideBatchSuggestions(rowIdx);
        } else {
            // Clear all fields
            document.getElementsByName('material_id[]')[rowIdx].value = '';
            document.getElementsByName('unit[]')[rowIdx].value = '';
            document.getElementsByName('available_qty[]')[rowIdx].value = '';
            document.getElementsByName('batch_no[]')[rowIdx].value = '';
            hideActualQtyDisplay(rowIdx);
            hideBatchSuggestions(rowIdx);
        }
    }

    function onBatchInput(input, rowIdx) {
        const batchValue = input.value.trim();
        const materialId = document.getElementsByName('material_id[]')[rowIdx].value;
        const materialDesc = document.getElementsByName('description[]')[rowIdx].value;

        if (batchValue.length > 0 && materialId && materialDesc) {
            showBatchSuggestions(materialId, materialDesc, batchValue, rowIdx);
            
            // Check if exact batch exists and show quantity
            const batchKey = materialId + '||' + materialDesc + '||' + batchValue;
            const batchData = batchActualQtyMap[batchKey];
            
            if (batchData) {
                showActualQtyDisplay(rowIdx, batchData.actual_qty, batchData.batch_count);
            } else {
                // Show "0 Available" for non-matching batch
                showActualQtyDisplay(rowIdx, 0, 0, true);
            }
        } else {
            hideBatchSuggestions(rowIdx);
            hideActualQtyDisplay(rowIdx);
        }
    }

    function showBatchSuggestions(materialId, materialDesc, inputValue, rowIdx) {
        const materialKey = materialId + '||' + materialDesc;
        const suggestionsDiv = document.getElementById(`batchSuggestions_${rowIdx}`);
        
        if (!materialBatchesMap[materialKey]) {
            hideBatchSuggestions(rowIdx);
            return;
        }

        // Filter batches that match input
        const matchingBatches = materialBatchesMap[materialKey].filter(batch => 
            batch.batch_no.toLowerCase().includes(inputValue.toLowerCase())
        );

        if (matchingBatches.length > 0) {
            let suggestionsHTML = '';
            matchingBatches.forEach(batch => {
                suggestionsHTML += `
                    <div class="batch-suggestion-item" 
                         style="padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #eee;"
                         onclick="selectBatchSuggestion('${batch.batch_no}', ${rowIdx})"
                         onmouseover="this.style.backgroundColor='#f8f9fa'"
                         onmouseout="this.style.backgroundColor='white'">
                        <div style="font-weight: 600; color: #2c3e50;">${batch.batch_no}</div>
                        <div style="font-size: 0.8rem; color: #28a745;">
                            <i class="fas fa-box"></i> Qty: ${batch.batch_qty} 
                            <span style="color: #6c757d;">(${batch.entry_count} entries)</span>
                        </div>
                    </div>
                `;
            });
            
            suggestionsDiv.innerHTML = suggestionsHTML;
            suggestionsDiv.style.display = 'block';
        } else {
            hideBatchSuggestions(rowIdx);
        }
    }

    function selectBatchSuggestion(batchNo, rowIdx) {
        document.getElementsByName('batch_no[]')[rowIdx].value = batchNo;
        hideBatchSuggestions(rowIdx);
        
        // Trigger batch validation
        const input = document.getElementsByName('batch_no[]')[rowIdx];
        validateBatch(input, rowIdx);
    }

    function hideBatchSuggestions(rowIdx) {
        const suggestionsDiv = document.getElementById(`batchSuggestions_${rowIdx}`);
        if (suggestionsDiv) {
            suggestionsDiv.style.display = 'none';
        }
    }

    function validateBatch(input, rowIdx) {
        const batchValue = input.value.trim();
        const materialId = document.getElementsByName('material_id[]')[rowIdx].value;
        const materialDesc = document.getElementsByName('description[]')[rowIdx].value;

        if (batchValue && materialId && materialDesc) {
            const batchKey = materialId + '||' + materialDesc + '||' + batchValue;
            const batchData = batchActualQtyMap[batchKey];
            
            if (batchData) {
                showActualQtyDisplay(rowIdx, batchData.actual_qty, batchData.batch_count);
                input.style.borderColor = '#28a745'; // Green border for valid batch
            } else {
                showActualQtyDisplay(rowIdx, 0, 0, true);
                input.style.borderColor = '#ffc107'; // Yellow border for new/unknown batch
            }
        } else {
            hideActualQtyDisplay(rowIdx);
            input.style.borderColor = '#ced4da'; // Default border
        }
        
        hideBatchSuggestions(rowIdx);
    }

    function showActualQtyDisplay(rowIdx, actualQty, batchCount, isNewBatch = false) {
        const displayElement = document.getElementById(`actualQtyDisplay_${rowIdx}`);
        const qtySpan = displayElement.querySelector('.qty-value');
        
        qtySpan.textContent = actualQty;
        displayElement.style.display = 'block';
        
        // Update styling based on quantity and batch status
        if (isNewBatch) {
            displayElement.style.backgroundColor = '#fff3cd';
            displayElement.style.border = '1px solid #ffc107';
            displayElement.querySelector('.qty-info').innerHTML = 
                '<i class="fas fa-exclamation-triangle text-warning"></i> New Batch (0 Available)';
        } else if (actualQty > 0) {
            displayElement.style.backgroundColor = '#d4edda';
            displayElement.style.border = '1px solid #c3e6cb';
            displayElement.querySelector('.qty-info').innerHTML = 
                `<i class="fas fa-box text-success"></i> <span class="qty-value">${actualQty}</span> Available`;
        } else {
            displayElement.style.backgroundColor = '#f8d7da';
            displayElement.style.border = '1px solid #f5c6cb';
            displayElement.querySelector('.qty-info').innerHTML = 
                '<i class="fas fa-times-circle text-danger"></i> <span class="qty-value">0</span> Available';
        }
        
        // Add fade-in effect
        displayElement.style.opacity = '0';
        setTimeout(() => {
            displayElement.style.opacity = '1';
            displayElement.style.transition = 'opacity 0.3s ease-in-out';
        }, 100);
    }

    function hideActualQtyDisplay(rowIdx) {
        const displayElement = document.getElementById(`actualQtyDisplay_${rowIdx}`);
        if (displayElement) {
            displayElement.style.display = 'none';
        }
    }

    function showBatchDetailsPopup(rowIdx) {
        const materialId = document.getElementsByName('material_id[]')[rowIdx].value;
        const materialDesc = document.getElementsByName('description[]')[rowIdx].value;
        const batchNo = document.getElementsByName('batch_no[]')[rowIdx].value;
        
        if (!materialId || !materialDesc || !batchNo) {
            alert('Please select material and enter batch number first.');
            return;
        }

        const batchKey = materialId + '||' + materialDesc + '||' + batchNo;
        const batchData = batchActualQtyMap[batchKey];
        
        let popupContent = `
            <div style="padding: 20px; text-align: center;">
                <h5 style="color: #2c3e50; margin-bottom: 20px;">
                    <i class="fas fa-info-circle"></i> Batch Details
                </h5>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px; text-align: left;">
                    <p><strong>Material ID:</strong> ${materialId}</p>
                    <p><strong>Description:</strong> ${materialDesc}</p>
                    <p><strong>Batch Number:</strong> ${batchNo}</p>
                </div>`;
        
        if (batchData) {
            popupContent += `
                <div style="background: #d4edda; padding: 15px; border-radius: 8px; border: 2px solid #28a745;">
                    <h4 style="color: #155724; margin: 0 0 10px 0;">
                        <i class="fas fa-box"></i> Available Quantity: ${batchData.actual_qty}
                    </h4>
                    <small style="color: #155724;">
                        Based on ${batchData.batch_count} batch entries
                        ${batchData.latest_entry ? `<br>Latest entry: ${new Date(batchData.latest_entry).toLocaleDateString()}` : ''}
                    </small>
                </div>`;
        } else {
            popupContent += `
                <div style="background: #fff3cd; padding: 15px; border-radius: 8px; border: 2px solid #ffc107;">
                    <h4 style="color: #856404; margin: 0 0 10px 0;">
                        <i class="fas fa-exclamation-triangle"></i> New Batch Entry
                    </h4>
                    <small style="color: #856404;">
                        This appears to be a new batch number.<br>
                        Available quantity: 0
                    </small>
                </div>`;
        }
        
        popupContent += '</div>';
        
        // Create and show modal
        const modalHTML = `
            <div class="modal fade" id="batchDetailsModal" tabindex="-1" role="dialog">
                <div class="modal-dialog modal-dialog-centered" role="document">
                    <div class="modal-content">
                        <div class="modal-header" style="background: linear-gradient(135deg, #667eea, #764ba2); color: white;">
                            <h5 class="modal-title">Batch Quantity Information</h5>
                            <button type="button" class="close" data-dismiss="modal" style="color: white; opacity: 1;">
                                <span>&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            ${popupContent}
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-primary" data-dismiss="modal">
                                <i class="fas fa-check"></i> OK
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Remove existing modal if any
        $('#batchDetailsModal').remove();
        
        // Add new modal to body
        $('body').append(modalHTML);
        $('#batchDetailsModal').modal('show');
        
        // Remove modal after hiding
        $('#batchDetailsModal').on('hidden.bs.modal', function () {
            $(this).remove();
        });
    }

    // Hide suggestions when clicking outside
    document.addEventListener('click', function(event) {
        if (!event.target.closest('.batch-input') && !event.target.closest('.batch-suggestions')) {
            $('[id^="batchSuggestions_"]').hide();
        }
    });

    // Rest of your existing functions (addRow, updateShortIssue, etc.) remain the same...
    
    function addRow() {
        const tableBody = document.getElementById("materialBody");
        const rowCount = tableBody.rows.length;
        const newRow = tableBody.insertRow();
        newRow.classList.add("text-center");
        newRow.innerHTML = `
            <td>
                <button type="button" class="btn btn-link text-danger p-0 delete-row-btn" title="Delete Row">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </td>
            <td>${rowCount + 1}</td>
            <td><input type="text" class="form-control" name="material_id[]" readonly></td>
            <td>
                <select class="form-control material-select2" name="description[]" onchange="onMaterialChange(this, ${rowCount})" style="width: 220px;">
                    <option value="">-- Select Material --</option>
                    <?php foreach ($materials as $mat): ?>
                        <option value="<?= htmlspecialchars($mat['material_description']) ?>"><?= htmlspecialchars($mat['material_description']) ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="text" class="form-control" name="unit[]" readonly></td>
            <td>
                <div style="position: relative;">
                    <input type="text" class="form-control batch-input" name="batch_no[]" 
                           maxlength="50" style="width:140px;" placeholder="Enter Batch No"
                           oninput="onBatchInput(this, ${rowCount})"
                           onblur="validateBatch(this, ${rowCount})">
                    
                    <div class="batch-suggestions" id="batchSuggestions_${rowCount}" 
                         style="display: none; position: absolute; top: 100%; left: 0; right: 0; 
                                background: white; border: 1px solid #ddd; border-radius: 4px; 
                                max-height: 200px; overflow-y: auto; z-index: 1000; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    </div>
                    
                    <div class="actual-qty-display mt-2" id="actualQtyDisplay_${rowCount}" 
                         style="font-size: 0.85rem; padding: 6px 8px; border-radius: 4px; 
                                text-align: center; display: none; cursor: pointer; transition: all 0.3s ease;"
                         onclick="showBatchDetailsPopup(${rowCount})">
                        <div class="qty-info">
                            <i class="fas fa-box text-success"></i> 
                            <span class="qty-value">0</span> Available
                        </div>
                        <div class="batch-info" style="font-size: 0.75rem; color: #6c757d; margin-top: 2px;">
                            <i class="fas fa-info-circle"></i> Click for details
                        </div>
                    </div>
                </div>
            </td>
            <td><input type="number" class="form-control" name="request_qty[]" oninput="updateShortIssue(${rowCount})"></td>
            <td><input type="number" class="form-control" name="issued_qty[]" readonly disabled></td>
            <td><input type="text" class="form-control" name="available_qty[]" readonly></td>
        `;
        
        // Re-initialize Select2 for the new dropdown
        $(newRow).find('.material-select2').select2({
            placeholder: "-- Select Material --",
            allowClear: true,
            width: 'resolve'
        });
    }

    // Initialize Select2 on page load
    $(document).ready(function() {
        $('.material-select2').select2({
            placeholder: "-- Select Material --",
            allowClear: true,
            width: 'resolve'
        });
    });

    // Handle row deletion
    $(document).on('click', '.delete-row-btn', function() {
        const row = $(this).closest('tr');
        row.remove();
        // Re-index Sr. No.
        $('#materialBody tr').each(function(i, tr) {
            $(tr).find('td').eq(1).text(i + 1);
        });
    });

    function updateShortIssue(rowIdx) {
        const reqQtyInputs = document.getElementsByName('request_qty[]');
        const availQtyInputs = document.getElementsByName('available_qty[]');
        const shortIssueInputs = document.getElementsByName('short_issue[]');
        const reqQty = parseFloat(reqQtyInputs[rowIdx]?.value) || 0;
        const availQty = parseFloat(availQtyInputs[rowIdx]?.value) || 0;
        if (shortIssueInputs[rowIdx]) {
            shortIssueInputs[rowIdx].value = reqQty - availQty;
        }
    }
</script>