<?php
// Check for empty fields
if(empty($_POST['name'])  		||
   empty($_POST['email']) 		||
   empty($_POST['phone']) 		||
   empty($_POST['message'])	||
   !filter_var($_POST['email'],FILTER_VALIDATE_EMAIL))
   {
	echo "No arguments Provided!";
	return false;
   }

$name = $_POST['name'];
$email = $_POST['email'];
$phone = $_POST['phone'];
$message = $_POST['message'];


// Create the email and send the message
$to = 'info@empirejiujitsu.com'; // Add your email address inbetween the '' replacing yourname@yourdomain.com - This is where the form will send a message to.
$email_subject = "New message to EmpireBJJ";
$email_body = "You have received a new message from the website contact form.\n\n"."Here are the details:\n\nName: $name\n\nEmail: $email_address\n\nPhone: $phone\n\nMessage:\n$message";
$headers = "From: noreply@empirejiujitsu.com\n"; // This is the email address the generated message will be from. We recommend using something like noreply@yourdomain.com.
$headers .= "Reply-To: $email";
mail($to,$email_subject,$email_body,$headers);
//AutoRespond
$respondSubject = 'Thank you for contacting Empire Jiu Jitsu';
$respondBody = "Thank you for your loyalty and support.\n\nWe are open for classes and registration, information at http://www.empirebjj.com/\n\n- Kurt, Jake, and Empire family";
$respondHeaders = 'From: ' .' <'.$to.'>' . "\r\n" . 'Reply-To: ' . $to;

mail($email, $respondSubject, $respondBody, $respondHeaders);


//MailChimp
// MailChimp API credentials

// MailChimp API URL
$memberID = md5(strtolower($email));
$dataCenter = substr($apiKey,strpos($apiKey,'-')+1);
$url = 'https://' . $dataCenter . '.api.mailchimp.com/3.0/lists/' . $listID . '/members/' . $memberID;

// member information
$json = json_encode(array(
    'email_address' => $email,
    'status'        => 'subscribed'));

// send a HTTP POST request with curl
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_USERPWD, 'user:' . $apiKey);
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

return true;
?>
