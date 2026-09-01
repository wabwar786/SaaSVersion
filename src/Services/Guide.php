<?php
namespace Aio\Services;

/**
 * Guide — har module ki tafseeli rehnumai, software ke andar.
 *
 * Pehle kisi module par koi madad nahi thi. Naya customer screen dekh
 * kar andaza lagata tha ke kya karna hai, ghalat tareeqe se kaam karta
 * tha, aur phir support ko phone karta tha. Har guide ek hi shakl mein
 * hai taake har page par ek jaisa dikhe:
 *
 *   what   — yeh module karta kya hai (ek line)
 *   steps  — pehli dafa kya karna hai, tarteeb se
 *   tips   — wo baatein jo customer ko baad mein pata chalti hain
 *   warn   — wo ghaltiyan jo waqai nuqsan deti hain
 */
final class Guide
{
    public static function get(string $key): ?array
    {
        $all = self::all();
        return $all[$key] ?? null;
    }

    public static function all(): array
    {
        return [

'pos' => ['title'=>'Sale Point (POS)',
 'what'=>'Take orders, send them to the kitchen and close bills. This is the screen your cashier uses all day.',
 'steps'=>[
   'Open a shift first (Opening & Closing Shift). Without an open shift, cash cannot be counted at the end of the day.',
   'Pick a table for dine-in, or choose Takeaway / Delivery at the top.',
   'Tap items to add them. Tap the quantity buttons to change amounts.',
   'Press "Send to Kitchen" — this prints the KOT on the printer set for each item\'s category.',
   'When the guest is ready, choose the payment method and press Pay. The bill prints and stock is deducted.',
 ],
 'tips'=>[
   'Cash and card can have different tax rates. Set both in Settings; the POS picks the right one automatically.',
   'The bill number continues across days. It never restarts, so bills stay unique for FBR.',
   'If a table already has a bill, opening that table shows the full running bill, not a new one.',
 ],
 'warn'=>[
   'Items already sent to the kitchen cannot simply be reduced — the kitchen has already made them. Use Void / Refund with a manager password.',
 ]],

'tablet' => ['title'=>'Order Taker Tablet',
 'what'=>'A waiter\'s tablet on the same WiFi as the POS computer. It takes orders at the table.',
 'steps'=>[
   'On the POS computer, press "Connect a tablet". A QR code appears.',
   'On the tablet, connect to the same WiFi and scan that code.',
   'The tablet opens on Hold Bills — every dining table with its running total.',
   'Tap a table, add items, then "Send to Kitchen" or "Hold".',
 ],
 'tips'=>[
   'Any order taker can work on any table. The tablet always shows the whole bill, not just what that waiter added.',
   'The Cash / Card buttons change the tax, so you can tell the guest the exact total for how they will pay.',
   'The system records which tablet punched each item, so you can always check later who added what.',
 ],
 'warn'=>[
   'The tablet only works while the POS computer is switched on. It is not a separate till.',
   'If the pairing code expires, just press "Connect a tablet" on the POS again.',
 ]],

'kds' => ['title'=>'Kitchen Display (KDS)',
 'what'=>'A screen in the kitchen showing live orders instead of paper tickets.',
 'steps'=>[
   'Open this screen on a tablet or monitor in the kitchen.',
   'New orders appear on the left as soon as the POS sends them.',
   'Tap a ticket to move it: New to Preparing to Ready to Done.',
 ],
 'tips'=>[
   'Tickets older than 15 minutes move to Delayed by themselves. Watch that column during rush.',
   'Each printer/station only shows its own items, so the grill screen does not show drinks.',
 ],
 'warn'=>['If a status change fails, the card goes back to where it was. Never assume it saved without seeing it move.']],

'menu' => ['title'=>'Menu & Categories',
 'what'=>'Your items, their prices and which category they belong to.',
 'steps'=>[
   'Create categories first (Starters, Main Course, Drinks...).',
   'Add items under each category with their selling price.',
   'In Printers, set which printer each category prints on.',
 ],
 'tips'=>[
   'Category decides which kitchen printer gets the item. Drinks to the bar, grill to the grill station.',
   'Add a recipe for an item (Recipe & Food Cost) and stock will deduct automatically when it sells.',
 ],
 'warn'=>['An item already used in bills cannot be deleted — history would break. Mark it inactive instead.']],

'inventory' => ['title'=>'Inventory',
 'what'=>'Raw materials you buy: flour, chicken, oil, packaging.',
 'steps'=>[
   'Create categories, then add items with their purchase unit (KG, Litre, Piece).',
   'Set the minimum level so low stock is visible.',
   'Stock comes in through Purchasing, and goes out through recipes when items sell.',
 ],
 'tips'=>['These are not menu items. Menu items are what you sell; inventory is what you buy.'],
 'warn'=>['An item with stock still on hand cannot be deleted. Clear it through Wastage or a Transfer first.']],

'purchasing' => ['title'=>'Purchasing',
 'what'=>'Receiving goods from suppliers. Every receipt increases stock and updates cost.',
 'steps'=>[
   'Add your suppliers first (Suppliers).',
   'Create a receipt, pick the supplier, add items with quantity and cost.',
   'Save — stock goes up immediately and the average cost is recalculated.',
 ],
 'tips'=>['The cost you enter here feeds Profit & Loss. Wrong costs mean wrong profit.'],
 'warn'=>['Cancelling a receipt reverses the stock. Do not cancel a receipt whose goods are already used.']],

'recipe' => ['title'=>'Recipe & Food Cost',
 'what'=>'What each menu item is made of. This is how stock deducts automatically and how profit is calculated.',
 'steps'=>[
   'Pick a menu item.',
   'Add the inventory items it uses and the quantity per plate.',
   'Save. From now on, selling that item deducts those ingredients.',
 ],
 'tips'=>['Items without a recipe show zero cost, so Profit & Loss will look better than reality. Add recipes for your top sellers first.'],
 'warn'=>[]],

'tables' => ['title'=>'Tables & Floors',
 'what'=>'Your dining tables, grouped by floor or area.',
 'steps'=>['Add a floor name (Ground Floor, Rooftop).','Add tables with their seat count.'],
 'tips'=>['These same tables appear on the POS and on the order taker tablet.'],
 'warn'=>['A table with an open bill cannot be deleted. Close the bill first.']],

'printers' => ['title'=>'Printers / Devices',
 'what'=>'Your receipt and kitchen printers, and which category prints where.',
 'steps'=>[
   'Add each printer with its IP address (usually printed on the printer or in its settings menu).',
   'Press Check — this actually connects to the printer, it is not a guess.',
   'Press Test print — real paper should come out.',
   'Below, set which menu category goes to which printer.',
 ],
 'tips'=>[
   'One bill with items from three categories prints three separate kitchen tickets, one per printer.',
   'Default port is 9100 for almost all network thermal printers.',
 ],
 'warn'=>['Printers only work in the offline version — they live on your local network, not on the internet.']],

'shift' => ['title'=>'Opening & Closing Shift',
 'what'=>'The cash till. Open at the start, count and close at the end.',
 'steps'=>[
   'Press "Open shift" and enter the cash you are starting with.',
   'Take orders all day as normal.',
   'At the end, press "Close shift", count the cash in the drawer and enter the total.',
 ],
 'tips'=>['The system works out what should be in the drawer and shows the difference. Count first, then enter — do not look at the expected figure first.'],
 'warn'=>['Without an open shift, cash payments cannot be matched to a till and the closing figure will be wrong.']],

'orders' => ['title'=>'Running Orders',
 'what'=>'Every bill still open on the POS right now.',
 'steps'=>['Open the page to see all open bills, their table and how long they have been open.'],
 'tips'=>['Bills open longer than 45 minutes show in red. Usually it means someone forgot to close it.'],
 'warn'=>[]],

'void' => ['title'=>'Void / Refund',
 'what'=>'Cancelling a bill that was already closed.',
 'steps'=>['Find the bill.','Press Void.','Enter the reason and a manager password.'],
 'tips'=>['The bill is not deleted. The number stays in history, payments are cancelled and the stock comes back.'],
 'warn'=>['Every void is recorded with who did it and why. Check this report weekly — a rising number of voids often means something is wrong.']],

'reports' => ['title'=>'Reports',
 'what'=>'Fifteen built-in reports, plus your own custom reports.',
 'steps'=>['Pick a report from the list.','Set the date range.','Press Run, or Export CSV for Excel.'],
 'tips'=>[
   'Start with Sales summary and Profit & Loss.',
   'Sales by hour tells you when to put extra staff on.',
   'Use "Custom report" to build your own from any table.',
 ],
 'warn'=>['Profit & Loss uses recipe cost. Without recipes, cost shows as zero and profit looks higher than it is.']],

'expenses' => ['title'=>'Expenses',
 'what'=>'Money going out: rent, salaries, gas, repairs.',
 'steps'=>['Add categories once.','Record each expense with its date and amount.'],
 'tips'=>['These feed straight into Profit & Loss and the cash book.'],
 'warn'=>[]],

'accounting' => ['title'=>'Accounting / Cash',
 'what'=>'A day-by-day cash book: money in from sales, money out in expenses.',
 'steps'=>['Open the page to see the current month.'],
 'tips'=>['Cash and card are shown separately so you know how much should physically be in the drawer.'],
 'warn'=>[]],

'customers' => ['title'=>'Customers',
 'what'=>'Guest records with phone numbers, used for delivery and loyalty.',
 'steps'=>['Add customers, or let the POS create them while taking a delivery order.'],
 'tips'=>['The Loyalty page builds tiers automatically from what each customer has actually spent.'],
 'warn'=>[]],

'suppliers' => ['title'=>'Suppliers',
 'what'=>'The businesses you buy raw material from.',
 'steps'=>['Add each supplier with a phone number and city.'],
 'tips'=>['Purchases report shows how much you buy from each supplier.'],
 'warn'=>['A supplier with purchase history cannot be deleted. Mark them inactive instead.']],

'users' => ['title'=>'Users & Access',
 'what'=>'Who can log in and what each person is allowed to see.',
 'steps'=>['Add a user with an email and password.','Tick the modules they should see.','Use Password to change it later; use Suspend to block someone temporarily.'],
 'tips'=>['A user with zero modules sees an empty menu. If someone reports a blank screen, check this first.'],
 'warn'=>['The last remaining admin cannot be deleted or suspended, otherwise nobody could get back in.']],

'activate' => ['title'=>'Activate / Renew',
 'what'=>'Pay for your software and tell us, so we can activate it.',
 'steps'=>[
   'Send the payment using any of the accounts shown on the left of that screen.',
   'Fill in the transaction ID exactly as it appears on your receipt, the amount, and how many months you want.',
   'Press "Send payment details". You can keep working while we check.',
 ],
 'tips'=>[
   'Activation happens within 12 hours once we can see the payment.',
   'Any days left on your current period are added on top, so nothing is wasted.',
   'Send the request once. Sending it again does not make it faster.',
 ],
 'warn'=>[
   'Enter the exact reference from your receipt. A wrong reference is the usual reason a request is held up.',
   'When your period ends the software pauses, but your data is never deleted.',
 ]],

'settings' => ['title'=>'Settings',
 'what'=>'Business details, tax rates, receipt layout and FBR.',
 'steps'=>[
   'Fill in your business name, branch, phone and NTN — these print on every bill.',
   'Set the cash and card tax rates.',
   'Choose a bill template and press Preview to see exactly what will print.',
 ],
 'tips'=>['The two tax rates are the same ones the POS uses. Change them here and the POS follows immediately.'],
 'warn'=>['FBR settings only work in the offline version, because the FBR service runs on your own computer.']],

'wastage' => ['title'=>'Wastage / Adjustment',
 'what'=>'Recording stock that was spoiled, broken or thrown away.',
 'steps'=>['Choose the item, the quantity and the reason.'],
 'tips'=>['Stock reduces immediately, so your inventory stays honest.'],
 'warn'=>['Wastage cannot be edited afterwards. Post a correcting entry instead — that keeps the audit trail true.']],

'transfer' => ['title'=>'Stock Transfer',
 'what'=>'Moving stock from one branch to another.',
 'steps'=>['Open the branch that has the stock.','Choose the receiving branch, the items and quantities.'],
 'tips'=>['Stock moves on both sides in one step: minus here, plus there.'],
 'warn'=>[]],

'count' => ['title'=>'Physical Stock Count',
 'what'=>'Counting what is actually on the shelf and correcting the system to match.',
 'steps'=>['Start a count for a storage location.','Enter the counted quantity for each item.','Post the count.'],
 'tips'=>['The difference is adjusted automatically, so after a count the system equals the shelf.'],
 'warn'=>['Count when the kitchen is closed. Counting during service gives wrong figures.']],

'promotions' => ['title'=>'Discounts / Promotions',
 'what'=>'Discounts the POS can apply.',
 'steps'=>['Create a promotion, choose percent or amount, and set the dates.'],
 'tips'=>['Set a minimum bill so a small order does not get a big discount.'],
 'warn'=>['Check the Voids and discounts report regularly. Unusual discount patterns are worth a look.']],

'reservations' => ['title'=>'Reservations',
 'what'=>'Table bookings.',
 'steps'=>['Add the guest name, phone, date and time, and number of guests.'],
 'tips'=>['Take a deposit for large bookings and record it here.'],
 'warn'=>[]],

'delivery' => ['title'=>'Delivery',
 'what'=>'Delivery orders and which rider is carrying them.',
 'steps'=>['Take a delivery order on the POS.','Assign a rider.','Mark it delivered when the rider returns.'],
 'tips'=>['Cash held by each rider shows on the Rider page.'],
 'warn'=>[]],

'riders' => ['title'=>'Rider Management',
 'what'=>'Your delivery riders and their live status.',
 'steps'=>['Add each rider with a phone number and vehicle number.'],
 'tips'=>['Active jobs and today\'s deliveries come from real delivery orders, not typed in.'],
 'warn'=>['A rider with deliveries still out cannot be deleted.']],

'staff' => ['title'=>'Staff',
 'what'=>'Your employees.',
 'steps'=>['Add each staff member with a code, job title and joining date.'],
 'tips'=>['This is the employee record. To give someone a login, use Users & Access — keeping them separate keeps permissions clear.'],
 'warn'=>[]],

'loyalty' => ['title'=>'Loyalty / Membership',
 'what'=>'Customer tiers and points, built from what they have actually spent.',
 'steps'=>['Nothing to set up. It fills in as customers buy.'],
 'tips'=>['Gold above 100,000, Silver above 30,000. One point per 100 spent.'],
 'warn'=>[]],

'branches' => ['title'=>'Multi-Branch',
 'what'=>'All branches of this business and today\'s sales at each.',
 'steps'=>['Open the page to compare branches.'],
 'tips'=>['New branches are created by your provider, not from this screen.'],
 'warn'=>[]],

'online' => ['title'=>'Online Orders',
 'what'=>'Delivery and QR orders from the last three days.',
 'steps'=>['Open the page; press Refresh to check for new ones.'],
 'tips'=>['QR orders come from the table QR codes; guests order from their own phone.'],
 'warn'=>[]],

'whatsapp' => ['title'=>'WhatsApp / Notifications',
 'what'=>'Messages the system tried to send to guests.',
 'steps'=>['Open the page to see what was sent and what failed.'],
 'tips'=>['Failed messages show the exact reason, usually a wrong phone number.'],
 'warn'=>[]],

'offline' => ['title'=>'Offline / Sync',
 'what'=>'Keeping the branch computer and the online portal in step.',
 'steps'=>['Nothing to do daily — sync runs by itself every minute in the background.','Press "Sync now" if you want to push immediately.'],
 'tips'=>[
   'The branch keeps working with no internet. Everything syncs when the connection comes back.',
   'Bills go up within seconds of being closed, not on the next cycle.',
 ],
 'warn'=>['If the page shows "Background sync: STOPPED", restart the software. Nothing will reach the portal until it runs.']],

'closing' => ['title'=>'Shift Closing History',
 'what'=>'Every past shift closing, and the ability to print any of them again.',
 'steps'=>['Set the date range.','Find the closing.','Press Print.'],
 'tips'=>[
   'A cashier sees only their own closings. A manager sees everyone.',
   'Reports are saved exactly as they were at closing time, so an old report never changes.',
 ],
 'warn'=>['If a closing shows "rebuilt", it was closed on an older build and the report is worked out from live data - it may differ slightly from the original paper.']],

'activity' => ['title'=>'User Activity Log',
 'what'=>'A permanent record of who did what: logins, sales, price changes, shift actions, deletions.',
 'steps'=>['Search by user or record, or filter by action.'],
 'tips'=>['Check this first whenever a figure looks wrong. It usually shows exactly what happened and who did it.'],
 'warn'=>['This log cannot be edited or deleted from anywhere in the software. That is deliberate - a log that can be changed is worthless.']],

'dashboard' => ['title'=>'Dashboard',
 'what'=>'Today at a glance: sales, bills, average bill and sync status.',
 'steps'=>['This is your home screen.'],
 'tips'=>['Compare today with yesterday to spot a slow day early.'],
 'warn'=>[]],

        ];
    }
}

// build: V72 build 2026-08-28
