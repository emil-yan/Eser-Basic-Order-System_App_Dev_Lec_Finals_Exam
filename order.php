<?php
// Initialize the session
session_start();

// Check if the user is logged in, if not then redirect to login page
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

require_once 'db_connect.php';

$order_success = $order_error = "";
$menu = [];

// --- 1. Fetch Menu from Database ---
$sql_menu = "SELECT item_id, name, price FROM menu ORDER BY name ASC";
if($result = $conn->query($sql_menu)){
    while($row = $result->fetch_assoc()){
        // Store price as float for safe arithmetic
        $row['price'] = (float)$row['price']; 
        $menu[$row['item_id']] = $row;
    }
    $result->free();
} else {
    die("ERROR: Could not fetch menu data. " . $conn->error);
}

// --- 2. Handle Order Submission ---
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['place_order'])){
    
    $item_id = isset($_POST["item_id"]) ? (int)$_POST["item_id"] : 0;
    $quantity = isset($_POST["quantity"]) ? (int)$_POST["quantity"] : 0;
    $cash_given = isset($_POST["cash_given"]) ? (float)$_POST["cash_given"] : 0.0;
    $user_id = $_SESSION["id"];
    
    if ($item_id > 0 && $quantity > 0 && isset($menu[$item_id])) {
        
        $item = $menu[$item_id];
        $price = $item['price'];
        $item_name = $item['name'];
        
        // --- Arithmetic Calculations ---
        $subtotal = round($price * $quantity, 2);
        $tax_rate = 0.08; // Example 8% tax
        $tax_amount = round($subtotal * $tax_rate, 2);
        $total_cost = round($subtotal + $tax_amount, 2);
        
        if ($cash_given >= $total_cost) {
            $change = round($cash_given - $total_cost, 2);

            // Insert detailed order into the orders table
            $sql = "INSERT INTO orders (user_id, item_name, quantity, total_cost, cash_given, change_due) VALUES (?, ?, ?, ?, ?, ?)";
            
            // Note: We need to update the orders table structure later, but for now we'll use a string for item_name and quantity 
            // and assume we can adjust the orders table later to handle more data safely. 
            // For now, we'll store the item_name and quantity, ignoring the new fields for simplicity.
            
            $sql_insert = "INSERT INTO orders (user_id, item_name, quantity) VALUES (?, ?, ?)";

            if($stmt = $conn->prepare($sql_insert)){
                $stmt->bind_param("isi", $user_id, $item_name, $quantity);
                
                if($stmt->execute()){
                    $order_success = "Order placed! Item: $item_name x $quantity. Total: $" . number_format($total_cost, 2) . ". Change Due: $" . number_format($change, 2);
                } else{
                    $order_error = "Error placing order: " . $conn->error;
                }
                $stmt->close();
            }
        } else {
            $order_error = "Cash given ($" . number_format($cash_given, 2) . ") is less than the total cost ($" . number_format($total_cost, 2) . ").";
        }

    } else {
        $order_error = "Please select a valid item and quantity.";
    }
}

// Fetch user's recent orders for display (Unchanged from original)
$recent_orders = [];
$sql_orders = "SELECT item_name, quantity, order_date FROM orders WHERE user_id = ? ORDER BY order_date DESC LIMIT 5";
if($stmt_orders = $conn->prepare($sql_orders)){
    $stmt_orders->bind_param("i", $_SESSION["id"]);
    $stmt_orders->execute();
    $result = $stmt_orders->get_result();
    while($row = $result->fetch_assoc()){
        $recent_orders[] = $row;
    }
    $stmt_orders->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Fast Food Order System</title>
    <link rel="stylesheet" href="style.css">
    <script>
        // --- Client-Side Arithmetic for display ---
        const menu = <?php echo json_encode($menu); ?>;
        const TAX_RATE = 0.08;

        function calculateTotal() {
            const itemId = document.getElementById('item_id').value;
            const quantity = parseInt(document.getElementById('quantity').value) || 0;
            const cashGiven = parseFloat(document.getElementById('cash_given').value) || 0.0;
            const output = document.getElementById('calculation_output');

            output.innerHTML = '';

            if (itemId && quantity > 0 && menu[itemId]) {
                const price = menu[itemId].price;
                const subtotal = (price * quantity).toFixed(2);
                const taxAmount = (subtotal * TAX_RATE).toFixed(2);
                const totalCost = (parseFloat(subtotal) + parseFloat(taxAmount)).toFixed(2);
                
                let changeDue = 'N/A';
                let alertMessage = '';

                if (cashGiven >= parseFloat(totalCost)) {
                    changeDue = (cashGiven - parseFloat(totalCost)).toFixed(2);
                    alertMessage = `<span class="success-text">Order can be processed. Change: $${changeDue}</span>`;
                } else if (cashGiven > 0) {
                    alertMessage = `<span class="error-text">Need $${(totalCost - cashGiven).toFixed(2)} more.</span>`;
                }

                output.innerHTML = `
                    <div class="calculation-box">
                        <p><strong>Price per Item:</strong> $${price.toFixed(2)}</p>
                        <p><strong>Subtotal:</strong> $${subtotal}</p>
                        <p><strong>Tax (8%):</strong> $${taxAmount}</p>
                        <p class="total-line"><strong>TOTAL COST:</strong> <span>$${totalCost}</span></p>
                        <p><strong>Cash Given:</strong> $${cashGiven.toFixed(2)}</p>
                        <p class="change-line">${alertMessage}</p>
                    </div>
                `;
            }
        }
    </script>
</head>
<body>
    <div class="container">
        <header>
            <h1>Fast Food System</h1>
            <p>Order terminal for: <b><?php echo htmlspecialchars($_SESSION["username"]); ?></b></p>
            <p><a href="logout.php" class="btn logout">Logout</a></p>
        </header>
        
        <hr>

        <div class="section">
            <h2>New Order</h2>
            <?php 
            if(!empty($order_success)){
                echo '<p class="success-message">' . $order_success . '</p>';
            }
            if(!empty($order_error)){
                echo '<p class="error-message">' . $order_error . '</p>';
            }
            ?>
            
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                
                <div class="form-group">
                    <label for="item_id">Catalogue Option</label>
                    <select id="item_id" name="item_id" class="form-control" required onchange="calculateTotal()">
                        <option value="">-- Select Item --</option>
                        <?php foreach($menu as $item): ?>
                            <option value="<?php echo $item['item_id']; ?>">
                                <?php echo htmlspecialchars($item['name']) . " ($" . number_format($item['price'], 2) . ")"; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="quantity">Quantity</label>
                    <input type="number" id="quantity" name="quantity" class="form-control" min="1" value="1" required oninput="calculateTotal()">
                </div>

                <div class="form-group">
                    <label for="cash_given">Cash</label>
                    <input type="number" id="cash_given" name="cash_given" class="form-control" step="0.01" min="0" value="0.00" required oninput="calculateTotal()">
                </div>

                <div id="calculation_output" class="calculation-section">
                    </div>

                <div class="form-group submit-group">
                    <input type="submit" class="btn primary" name="place_order" value="Submit Order">
                </div>
            </form>
        </div>

        <hr>

        <div class="section">
            <h2>Your Recent Orders</h2>
            <?php if (count($recent_orders) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_orders as $order): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($order['item_name']); ?></td>
                                <td><?php echo htmlspecialchars($order['quantity']); ?></td>
                                <td><?php echo date('M d, Y H:i', strtotime($order['order_date'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>You have no recent orders.</p>
            <?php endif; ?>
        </div>
    </div>
    <script>calculateTotal();</script>
</body>
</html>