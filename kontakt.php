<?php
session_start();
error_reporting(E_ERROR | E_PARSE);
date_default_timezone_set('Europe/Berlin');
require_once("AntiSpam.php");
$q = AntiSpam::getRandomQuestion();
header('Content-type: text/html; charset=utf-8');


#########################################################################
#	Kontaktformular.com         					                                #
#	http://www.kontaktformular.com        						                    #
#	All rights by KnotheMedia.de                                    			#
#-----------------------------------------------------------------------#
#	I-Net: http://www.knothemedia.de                            					#
#########################################################################
// Der Copyrighthinweis darf NICHT entfernt werden!


  $script_root = substr(__FILE__, 0,
                        strrpos(__FILE__,
                                DIRECTORY_SEPARATOR)
                       ).DIRECTORY_SEPARATOR;

require_once $script_root.'upload.php';

$remote = getenv("REMOTE_ADDR");

function encrypt($string, $key) {
	$result = '';
	for($i=0; $i<strlen($string); $i++) {
	   $char = substr($string, $i, 1);
	   $keychar = substr($key, ($i % strlen($key))-1, 1);
	   $char = chr(ord($char)+ord($keychar));
	   $result.=$char;
	}
	return base64_encode($result);
}

@require('config.php');
require_once("AntiSpam.php");
include("PHPMailer/Secureimage.php");
// form-data should be deleted
if ($_POST['delete']){
	unset($_POST);
}

// form has been sent
if ($_POST["kf-km"]) {

	// clean data
	$anrede		= stripslashes($_POST["anrede"]);
	$titel		= stripslashes($_POST["titel"]);
	$vorname   	= stripslashes($_POST["vorname"]);
	$name      	= stripslashes($_POST["name"]);
	$firma		= stripslashes($_POST["firma"]);
	$telefon		= stripslashes($_POST["telefon"]);
	$email      = stripslashes($_POST["email"]);
	$betreff   	= stripslashes($_POST["betreff"]);
	$nachricht  = stripslashes($_POST["nachricht"]);
	if($cfg['DATENSCHUTZ_ERKLAERUNG']) { $datenschutz = stripslashes($_POST["datenschutz"]); }
	if($cfg['Sicherheitscode']){
		$sicherheits_eingabe = encrypt($_POST["sicherheitscode"], "8h384ls94");
		$sicherheits_eingabe = str_replace("=", "", $sicherheits_eingabe);
	}

	$date = date("d.m.Y | H:i");
	$ip = $_SERVER['REMOTE_ADDR'];
	$UserAgent = $_SERVER["HTTP_USER_AGENT"];
	$host = getHostByAddr($remote);


	// formcheck
	if(isset($anrede) && $anrede == "") {
		$fehler['anrede'] = "<span class='errormsg'>Bitte w??hlen Sie eine <strong>Anrede</strong> aus.</span>";
	}
	
	if(!$vorname) {
		$fehler['vorname'] = "<span class='errormsg'>Geben Sie bitte Ihren <strong>Vornamen</strong> ein.</span>";
	}
	
	if(!$name) {
		$fehler['name'] = "<span class='errormsg'>Geben Sie bitte Ihren <strong>Nachnamen</strong> ein.</span>";
	}
	
	if (!preg_match("/^[0-9a-zA-Z??????_.-]+@[0-9a-z.-]+\.[a-z]{2,6}$/", $email)) {
		$fehler['email'] = "<span class='errormsg'>Geben Sie bitte Ihre <strong>E-Mail-Adresse</strong> ein.</span>";
	}
	
	if(!$betreff) {
		$fehler['betreff'] = "<span class='errormsg'>Geben Sie bitte einen <strong>Betreff</strong> ein.</span>";
	}
	
	if(!$nachricht) {
		$fehler['nachricht'] = "<span class='errormsg'>Geben Sie bitte eine <strong>Nachricht</strong> ein.</span>";
	}
	
	
	
	// -------------------- SPAMPROTECTION ERROR MESSAGES START ----------------------
	if($cfg['Sicherheitscode'] && $sicherheits_eingabe != $_SESSION['captcha_spam']){
		unset($_SESSION['captcha_spam']);
		$fehler['captcha'] = "<span class='errormsg'>Der <strong>Sicherheitscode</strong> wurde falsch eingegeben.</span>";
	} 
		

  if($cfg["Sicherheitsfrage"]){
	$answer = AntiSpam::getAnswerById(intval($_POST["q_id"]));
	if($_POST["q"] != $answer){
		$fehler['q_id12'] = "<span class='errormsg'>Bitte die <strong>Sicherheitsfrage</strong> richtig beantworten.</span>";
	}
  }



	if($cfg['Honeypot'] && (!isset($_POST["mail"]) || ''!=$_POST["mail"])){
		$fehler['Honeypot'] = "<span class='errormsg' style='display: block;'>Es besteht Spamverdacht. Bitte ??berpr??fen Sie Ihre Angaben.</span>";
	}
	
	if($cfg['Zeitsperre'] && (!isset($_POST["chkspmtm"]) || ''==$_POST["chkspmtm"] || '0'==$_POST["chkspmtm"] || (time() - (int) $_POST["chkspmtm"]) < (int) $cfg['Zeitsperre'])){
		$fehler['Zeitsperre'] = "<span class='errormsg' style='display: block;'>Bitte warten Sie einige Sekunden, bevor Sie das Formular erneut absenden.</span>";
	}
	
	if($cfg['Klick-Check'] && (!isset($_POST["chkspmkc"]) || 'chkspmhm'!=$_POST["chkspmkc"])){
		$fehler['Klick-Check'] = "<span class='errormsg' style='display: block;'>Sie m??ssen den Senden-Button mit der Maus anklicken, um das Formular senden zu k??nnen.</span>";
	}
	
	if($cfg['Links'] < preg_match_all('#http(s?)\:\/\/#is', $nachricht, $irrelevantMatches)){
		$fehler['Links'] = "<span class='errormsg' style='display: block;'>Ihre Nachricht darf ".(0==$cfg['Links'] ? 
																																'keine Links' : 
																																(1==$cfg['Links'] ? 
																																	'nur einen Link' : 
																																	'maximal '.$cfg['Links'].' Links'
																																)
																															)." enthalten.</span>";
	}
	
	if(''!=$cfg['Badwordfilter'] && 0!==$cfg['Badwordfilter'] && '0'!=$cfg['Badwordfilter']){
		$badwords = explode(',', $cfg['Badwordfilter']);			// the configured badwords
		$badwordFields = explode(',', $cfg['Badwordfields']);		// the configured fields to check for badwords
		$badwordMatches = array();									// the badwords that have been found in the fields
		
		if(0<count($badwordFields)){
			foreach($badwords as $badword){
				$badword = trim($badword);												// remove whitespaces from badword
				$badwordMatch = str_replace('%', '', $badword);							// take human readable badword for error-message
				$badword = addcslashes($badword, '.:/');								// make ., : and / preg_match-valid
				if('%'!=substr($badword, 0, 1)){ $badword = '\\b'.$badword; }			// if word mustn't have chars before > add word boundary at the beginning of the word
				if('%'!=substr($badword, -1, 1)){ $badword = $badword.'\\b'; }			// if word mustn't have chars after > add word boundary at the end of the word
				$badword = str_replace('%', '', $badword);								// if word is allowed in the middle > remove all % so it is also allowed in the middle in preg_match 
				foreach($badwordFields as $badwordField){
					if(preg_match('#'.$badword.'#is', $_POST[trim($badwordField)]) && !in_array($badwordMatch, $badwordMatches)){
						$badwordMatches[] = $badwordMatch;
					}
				}
			}		
			
			if(0<count($badwordMatches)){
				$fehler['Badwordfilter'] = "<span class='errormsg' style='display: block;'>Folgende Begriffe sind nicht erlaubt: ".implode(', ', $badwordMatches)."</span>";
			}
		}		
	}
  // -------------------- SPAMPROTECTION ERROR MESSAGES ENDE ----------------------
  
  
	if($cfg['DATENSCHUTZ_ERKLAERUNG'] && isset($datenschutz) && $datenschutz == ""){ 
		$fehler['datenschutz'] = "<span class='errormsg'>Sie m??ssen die <strong>Datenschutz&shy;erkl??rung</strong> akzeptieren.</span>";
	}

	// there are NO errors > upload-check
    if (!isset($fehler) || count($fehler) == 0) {
      $error             = false;
      $errorMessage      = '';
      $uploadErrors      = array();
      $uploadedFiles     = array();
      $totalUploadSize   = 0;

      if ($cfg['UPLOAD_ACTIVE'] && in_array($_SERVER['REMOTE_ADDR'], $cfg['BLACKLIST_IP']) === true) {
          $error = true;
          $fehler['upload'] = "<span class='errormsg'>Sie haben keine Erlaubnis Dateien hochzuladen.</span>";
      }

      if (!$error) {
          for ($i=0; $i < $cfg['NUM_ATTACHMENT_FIELDS']; $i++) {
              if ($_FILES['f']['error'][$i] == UPLOAD_ERR_NO_FILE) {
                  continue;
              }

              $extension = explode('.', $_FILES['f']['name'][$i]);
              $extension = strtolower($extension[count($extension)-1]);
              $totalUploadSize += $_FILES['f']['size'][$i];

              if ($_FILES['f']['error'][$i] != UPLOAD_ERR_OK) {
                  $uploadErrors[$j]['name'] = $_FILES['f']['name'][$i];
                  switch ($_FILES['f']['error'][$i]) {
                      case UPLOAD_ERR_INI_SIZE :
                          $uploadErrors[$j]['error'] = 'Die Datei ist zu gro?? (PHP-Ini Direktive).';
                      break;
                      case UPLOAD_ERR_FORM_SIZE :
                          $uploadErrors[$j]['error'] = 'Die Datei ist zu gro?? (MAX_FILE_SIZE in HTML-Formular).';
                      break;
                      case UPLOAD_ERR_PARTIAL :
						  if ($cfg['UPLOAD_ACTIVE']) {
                          	  $uploadErrors[$j]['error'] = 'Die Datei wurde nur teilweise hochgeladen.';
						  } else {
							  $uploadErrors[$j]['error'] = 'Die Datei wurde nur teilweise versendet.';
					  	  }
                      break;
                      case UPLOAD_ERR_NO_TMP_DIR :
                          $uploadErrors[$j]['error'] = 'Es wurde kein tempor??rer Ordner gefunden.';
                      break;
                      case UPLOAD_ERR_CANT_WRITE :
                          $uploadErrors[$j]['error'] = 'Fehler beim Speichern der Datei.';
                      break;
                      case UPLOAD_ERR_EXTENSION  :
                          $uploadErrors[$j]['error'] = 'Unbekannter Fehler durch eine Erweiterung.';
                      break;
                      default :
						  if ($cfg['UPLOAD_ACTIVE']) {
                          	  $uploadErrors[$j]['error'] = 'Unbekannter Fehler beim Hochladen.';
						  } else {
							  $uploadErrors[$j]['error'] = 'Unbekannter Fehler beim Versenden des Email-Attachments.';
						  }
                  }

                  $j++;
                  $error = true;
              }
              else if ($totalUploadSize > $cfg['MAX_ATTACHMENT_SIZE']*1024) {
                  $uploadErrors[$j]['name'] = $_FILES['f']['name'][$i];
                  $uploadErrors[$j]['error'] = 'Maximaler Upload erreicht ('.$cfg['MAX_ATTACHMENT_SIZE'].' KB).';
                  $j++;
                  $error = true;
              }
              else if ($_FILES['f']['size'][$i] > $cfg['MAX_FILE_SIZE']*1024) {
                  $uploadErrors[$j]['name'] = $_FILES['f']['name'][$i];
                  $uploadErrors[$j]['error'] = 'Die Datei ist zu gro?? (max. '.$cfg['MAX_FILE_SIZE'].' KB).';
                  $j++;
                  $error = true;
              }
              else if (!empty($cfg['BLACKLIST_EXT']) && strpos($cfg['BLACKLIST_EXT'], $extension) !== false) {
                  $uploadErrors[$j]['name'] = $_FILES['f']['name'][$i];
                  $uploadErrors[$j]['error'] = 'Die Dateiendung ist nicht erlaubt.';
                  $j++;
                  $error = true;
              }
              else if (preg_match("=^[\\:*?<>|/]+$=", $_FILES['f']['name'][$i])) {
                  $uploadErrors[$j]['name'] = $_FILES['f']['name'][$i];
                  $uploadErrors[$j]['error'] = 'Ung??ltige Zeichen im Dateinamen (\/:*?<>|).';
                  $j++;
                  $error = true;
              }
              else if ($cfg['UPLOAD_ACTIVE'] && file_exists($cfg['UPLOAD_FOLDER'].'/'.$_FILES['f']['name'][$i])) {
                  $uploadErrors[$j]['name'] = $_FILES['f']['name'][$i];
                  $uploadErrors[$j]['error'] = 'Die Datei existiert bereits.';
                  $j++;
                  $error = true;
              }
              else {
				  if ($cfg['UPLOAD_ACTIVE']) {
                     move_uploaded_file($_FILES['f']['tmp_name'][$i], $cfg['UPLOAD_FOLDER'].'/'.$_FILES['f']['name'][$i]);
				  }
                  $uploadedFiles[$_FILES['f']['tmp_name'][$i]] = $_FILES['f']['name'][$i];
              }
          }
      }

      if ($error) {
          $errorMessage = 'Es sind folgende Fehler beim Versenden des Kontaktformulars aufgetreten:'."\n";
          if (count($uploadErrors) > 0) {
              foreach ($uploadErrors as $err) {
                  $tmp .= '<strong>'.$err['name']."</strong><br/>\n- ".$err['error']."<br/><br/>\n";
              }
              $tmp = "<br/><br/>\n".$tmp;
          }
          $errorMessage .= $tmp.'';
          $fehler['upload'] = $errorMessage;
      }
	}


	// there are NO errors > send mail
   if (!isset($fehler))
   {
		// ------------------------------------------------------------
		// -------------------- send mail to admin --------------------
		// ------------------------------------------------------------

		// ---- create mail-message for admin
	  $mailcontent  = "Folgendes wurde am ". $date ." Uhr per Formular geschickt:\n" . "-------------------------------------------------------------------------\n\n";
		$mailcontent .= "Name: " . $anrede . " " . $titel . "" . $vorname . " " . $name . "\n";
		$mailcontent .= "Firma: " . $firma . "\n\n";
		$mailcontent .= "E-Mail: " . $email . "\n";
		$mailcontent .= "Telefon: " . $telefon . "\n";
		$mailcontent .= "\nBetreff: " . $betreff . "\n";
		$mailcontent .= "Nachricht:\n" . $nachricht = preg_replace("/\r\r|\r\n|\n\r|\n\n/","\n",$nachricht) . "\n\n";
		if(count($uploadedFiles) > 0){
			if($cfg['UPLOAD_ACTIVE']){
				$mailcontent .= 'Es wurden folgende Dateien hochgeladen:'."\n";
				foreach ($uploadedFiles as $filename) {
					$mailcontent .= ' - '.$cfg['DOWNLOAD_URL'].'/'.$cfg['UPLOAD_FOLDER'].'/'.$filename."\n";
				}
			} else {
				$mailcontent .= 'Es wurden folgende Dateien als Attachment angeh??ngt:'."\n";
				foreach ($uploadedFiles as $filename) {
					$mailcontent .= ' - '.$filename."\n";
				}
			}
		}
		if($cfg['DATENSCHUTZ_ERKLAERUNG']) { $mailcontent .= "\n\nDatenschutz: " . $datenschutz . " \n"; }
    $mailcontent .= "\n\nIP Adresse: " . $ip . "\n";
		$mailcontent = strip_tags ($mailcontent);

		// ---- get attachments for admin
		$attachments = array();
		if(!$cfg['UPLOAD_ACTIVE'] && count($uploadedFiles) > 0){
			foreach($uploadedFiles as $tempFilename => $filename) {
				$attachments[$filename] = file_get_contents($tempFilename);
			}
		}

		$success = false;

        // ---- send mail to admin
        if($smtp['enabled'] !== 0) {
            require_once __DIR__ . '/smtp.php';
            $success = SMTP::send(
                $smtp['host'],
                $smtp['user'],
                $smtp['password'],
                $smtp['encryption'],
                $smtp['port'],
                $email,
                $ihrname,
                $empfaenger,
                $betreff,
                $mailcontent,
                $uploadedFiles,
                $cfg['UPLOAD_FOLDER'],
                $smtp['debug']
            );
        } else {
            $success = sendMyMail($email, $vorname." ".$name, $empfaenger, $betreff, $mailcontent, $attachments);
        }

    	// ------------------------------------------------------------
    	// ------------------- send mail to customer ------------------
    	// ------------------------------------------------------------
    	if(
			$success && 
			(
				2==$cfg['Kopie_senden'] || 																// send copy always
				(1==$cfg['Kopie_senden'] && isset($_POST['mail-copy']) && 1==$_POST['mail-copy'])		// send copy only if customer want to
			)
		){

    		// ---- create mail-message for customer
			$mailcontent  = "Vielen Dank f??r Ihre E-Mail. Wir werden schnellstm??glich darauf antworten.\n\n";
    		$mailcontent .= "Zusammenfassung: \n" .  "-------------------------------------------------------------------------\n\n";
    		$mailcontent .= "Name: " . $anrede . " " . $titel . "" . $vorname . " " . $name . "\n";
    		$mailcontent .= "Firma: " . $firma . "\n\n";
    		$mailcontent .= "E-Mail: " . $email . "\n";
    		$mailcontent .= "Telefon: " . $telefon . "\n";
    		$mailcontent .= "\nBetreff: " . $betreff . "\n";
    		$mailcontent .= "Nachricht:\n" . str_replace("\r", "", $nachricht) . "\n\n";
    		if(count($uploadedFiles) > 0){
    			$mailcontent .= 'Sie haben folgende Dateien ??bertragen:'."\n";
    			foreach($uploadedFiles as $file){
    				$mailcontent .= ' - '.$file."\n";
    			}
    		}
    		$mailcontent = strip_tags ($mailcontent);

    		// ---- send mail to customer
            if($smtp['enabled'] !== 0) {
                SMTP::send(
                    $smtp['host'],
                    $smtp['user'],
                    $smtp['password'],
                    $smtp['encryption'],
                    $smtp['port'],
                    $empfaenger,
                    $ihrname,
                    $email,
                    "Ihre Anfrage",
                    $mailcontent,
                    array(),
                    $cfg['UPLOAD_FOLDER'],
                    $smtp['debug']
                );
            } else {
                $success = sendMyMail($empfaenger, $ihrname, $email, "Ihre Anfrage", $mailcontent);
            }
		}
		
		// redirect to success-page
		if($success){
			if($smtp['enabled'] === 0 || $smtp['debug'] === 0) {
    		    echo "<META HTTP-EQUIV=\"refresh\" content=\"0;URL=".$danke."\">";
            }

    		exit;
		}
	}
}

// clean post
foreach($_POST as $key => $value){
    $_POST[$key] = htmlentities($value, ENT_QUOTES, "UTF-8");
}
?>
<?php




function sendMyMail($fromMail, $fromName, $toMail, $subject, $content, $attachments=array()){

	$boundary = md5(uniqid(time()));
	$eol = PHP_EOL;

	// header
	$header = "From: =?UTF-8?B?".base64_encode(stripslashes($fromName))."?= <".$fromMail.">".$eol;
	$header .= "Reply-To: <".$fromMail.">".$eol;
	$header .= "MIME-Version: 1.0".$eol;
	if(is_array($attachments) && 0<count($attachments)){
		$header .= "Content-Type: multipart/mixed; boundary=\"".$boundary."\"";
	}
	else{
		$header .= "Content-type: text/plain; charset=utf-8";
	}


	// content with attachments
	if(is_array($attachments) && 0<count($attachments)){

		// content
		$message = "--".$boundary.$eol;
		$message .= "Content-type: text/plain; charset=utf-8".$eol;
		$message .= "Content-Transfer-Encoding: 8bit".$eol.$eol;
		$message .= $content.$eol;

		// attachments
		foreach($attachments as $filename=>$filecontent){
			$filecontent = chunk_split(base64_encode($filecontent));
			$message .= "--".$boundary.$eol;
			$message .= "Content-Type: application/octet-stream; name=\"".$filename."\"".$eol;
			$message .= "Content-Transfer-Encoding: base64".$eol;
			$message .= "Content-Disposition: attachment; filename=\"".$filename."\"".$eol.$eol;
			$message .= $filecontent.$eol;
		}
		$message .= "--".$boundary."--";
	}
	// content without attachments
	else{
		$message = $content;
	}

	// subject
	$subject = "=?UTF-8?B?".base64_encode($subject)."?=";

	// send mail
	return mail($toMail, $subject, $message, $header);
}

?>
<!DOCTYPE html>
<html lang="de-DE">
	<head>
		<meta charset="utf-8">
		<meta name="language" content="de"/>
		<meta name="description" content="kontaktformular.com"/>
		<meta name="revisit" content="After 7 days"/>
		<meta name="robots" content="INDEX,FOLLOW"/>
		<title>kontaktformular.com</title>

		<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0" />
	<!-- Stylesheet -->
<link href="/src/css/style-kontaktformular.css" rel="stylesheet">


<link href='https://fonts.googleapis.com/css?family=Heebo:700' rel='stylesheet' type='text/css'>


<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.1.1/jquery.min.js"></script></head>





<body>

	<div>
		<form id="kontaktformular" class="kontaktformular" action="<?php echo $_SERVER['PHP_SELF'];?>" method="post" enctype="multipart/form-data">


<script>
if (navigator.userAgent.search("Safari") >= 0 && navigator.userAgent.search("Chrome") < 0) 
{
   document.getElementsByTagName("BODY")[0].className += " safari";
}
	</script>



			<?php 
				if(
					$fehler["Honeypot"] != "" || 
					$fehler["Zeitsperre"] != "" ||
					$fehler['Klick-Check'] != "" ||
					$fehler['Links'] != "" ||
					$fehler['Badwordfilter'] != ""
				){
					?>
					<div class="row">
						<div class="col-sm-8">
							<?php if ($fehler["Honeypot"] != "") { echo $fehler["Honeypot"]; } ?>
							<?php if ($fehler["Zeitsperre"] != "") { echo $fehler["Zeitsperre"]; } ?>
							<?php if ($fehler["Klick-Check"] != "") { echo $fehler["Klick-Check"]; } ?>
							<?php if ($fehler["Links"] != "") { echo $fehler["Links"]; } ?>
							<?php if ($fehler["Badwordfilter"] != "") { echo $fehler["Badwordfilter"]; } ?>
						</div>
					</div>
					<?php
				}
			
			
			?>


				<div class="row">
				<div class="col-sm-8">
					<label class="control-label" for="border-right"><i id="briefcase-icon"  class="fa fa-briefcase"></i></label>
					<input aria-label="Firma" type="text" name="firma" class="field" placeholder="Firma" value="<?php echo $_POST[firma]; ?>" maxlength="<?php echo $zeichenlaenge_firma; ?>" id="border-right"/>
				</div>
			</div>



		<div class="row">
				<div class="col-sm-4 <?php if ($fehler["anrede"] != "") { echo 'error'; } ?>" >
					<label class="control-label select-label" for="border-right2" style="pointer-events: none; z-index: 1;" ><i id="caret-down-icon" class="fas fa-caret-down"></i></label>
					<select  id="border-right2" <?php if($cfg['HTML5_FEHLERMELDUNGEN']) { ?> required style="box-shadow: 0 0 1px rgba(0,0,0, .4);" <?php } ?> aria-label="Anrede" name="anrede" class="field unselected" onchange="<?php if(!$cfg['HTML5_FEHLERMELDUNGEN']) { ?> checkField(this); <?php } ?>if(0!=this.selectedIndex){ this.setAttribute('class', 'field'); }else{ this.setAttribute('class', 'field unselected'); }">
						<option value="" selected >Anrede *</option>
						<option style="color:#000" value="Herr" <?php if($_POST['anrede']=="Herr"){ echo "selected";}?> >Herr</option>
						<option style="color:#000" value="Frau" <?php if($_POST['anrede']=="Frau"){ echo "selected";}?> >Frau</option>
					</select>
					<?php if ($fehler["anrede"] != "") { echo $fehler["anrede"]; } ?>
					<script>if(0!=document.getElementsByName('anrede')[0].selectedIndex){ document.getElementsByName('anrede')[0].setAttribute('class', 'field'); }</script>
				</div>
				
				
				<div class="col-sm-4">
					<label class="control-label select-label" for="border-right3" style="pointer-events: none; z-index: 1;"><i id="caret-down-icon" class="fas fa-caret-down"></i></label>
					<select aria-label="Titel" name="titel" class="field unselected" onchange="if(0!=this.selectedIndex){ this.setAttribute('class', 'field'); }else{ this.setAttribute('class', 'field unselected'); }" id="border-right3">
						<option value="" selected >Titel</option>
						<option style="color:#000" value="Dr. " <?php if($_POST['titel']=="Dr. "){ echo "selected";}?> >Dr.</option>
						<option style="color:#000" value="Dr. med. " <?php if($_POST['titel']=="Dr. med. "){ echo "selected";}?> >Dr. med.</option>
						<option style="color:#000" value="Prof. " <?php if($_POST['titel']=="Prof. "){ echo "selected";}?> >Prof.</option>
						<option style="color:#000" value="Prof. Dr. " <?php if($_POST['titel']=="Prof. Dr. "){ echo "selected";}?> >Prof. Dr.</option>
						<option style="color:#000" value="Prof. Dr. med. " <?php if($_POST['titel']=="Prof. Dr. med. "){ echo "selected";}?> >Prof. Dr. med.</option>
					</select>
					<script>if(0!=document.getElementsByName('titel')[0].selectedIndex){ document.getElementsByName('titel')[0].setAttribute('class', 'field'); }</script>
				</div>
			</div>




				<div class="row">
				<div class="col-sm-4 <?php if ($fehler["vorname"] != "") { echo 'error'; } ?>">
					<label class="control-label" for="border-right4"><i id="user-icon" class="fa fa-user"></i></label>
					<input <?php if($cfg['HTML5_FEHLERMELDUNGEN']) { ?> required style="box-shadow: 0 0 1px rgba(0,0,0, .4);" <?php }else{ ?> onchange="checkField(this)" <?php } ?> aria-label="Vorname" type="text" name="vorname" class="field"  placeholder="Vorname *" value="<?php echo $_POST[vorname]; ?>" maxlength="<?php echo $zeichenlaenge_vorname; ?>" id="border-right4"/>
					<?php if ($fehler["vorname"] != "") { echo $fehler["vorname"]; } ?>
				</div>
				<div class="col-sm-4 <?php if ($fehler["name"] != "") { echo 'error'; } ?>">
					<label class="control-label" for="border-right5"><i id="user-icon" class="fa fa-user"></i></label>
					<input <?php if($cfg['HTML5_FEHLERMELDUNGEN']) { ?> required style="box-shadow: 0 0 1px rgba(0,0,0, .4);" <?php }else{ ?> onchange="checkField(this)" <?php } ?> type="text" name="name" class="field" placeholder="Nachname *" value="<?php echo $_POST[name]; ?>" maxlength="<?php echo $zeichenlaenge_name; ?>" id="border-right5"/>
					<?php if ($fehler["name"] != "") { echo $fehler["name"]; } ?>
				</div>
			</div>



			<div class="row">
				<div class="col-sm-4 <?php if ($fehler["email"] != "") { echo 'error'; } ?>">
					<label class="control-label" for="border-right6"><i id="email-icon" class="fa fa-envelope"></i></label>
					<input <?php if($cfg['HTML5_FEHLERMELDUNGEN']) { ?> required style="box-shadow: 0 0 1px rgba(0,0,0, .4);" <?php }else{ ?> onchange="checkField(this)" <?php } ?> aria-label="E-Mail" type="<?php if($cfg['HTML5_FEHLERMELDUNGEN']) { echo 'email'; }else{ echo 'text'; } ?>" name="email" class="field" placeholder="E-Mail *" value="<?php echo $_POST[email]; ?>" maxlength="<?php echo $zeichenlaenge_email; ?>" id="border-right6"/>
					<?php if ($fehler["email"] != "") { echo $fehler["email"]; } ?>
				</div>
				<div class="col-sm-4">
					<label class="control-label" for="border-right7"><i  id="phone-icon" class="fa fa-phone"></i></label>
					<input aria-label="Telefon" type="text" name="telefon" class="field" placeholder="Telefon/Mobil" value="<?php echo $_POST[telefon]; ?>" maxlength="<?php echo $zeichenlaenge_telefon; ?>" id="border-right7"/>
				</div>
			</div>

		   <div class="row">
				<div class="col-sm-8 <?php if ($fehler["betreff"] != "") { echo 'error'; } ?>">
					<label class="control-label" for="border-right8"><i id="subject-icon" class="fa fa-edit"></i></label>
					<input <?php if($cfg['HTML5_FEHLERMELDUNGEN']) { ?> required style="box-shadow: 0 0 1px rgba(0,0,0, .4);" <?php }else{ ?> onchange="checkField(this)" <?php } ?> aria-label="Betreff" type="text" name="betreff" class="field" placeholder="Betreff *" value="<?php echo $_POST[betreff]; ?>" maxlength="<?php echo $zeichenlaenge_betreff; ?>" id="border-right8"/>
					<?php if ($fehler["betreff"] != "") { echo $fehler["betreff"]; } ?>
				</div>
			</div>

			<div class="row">
				<div class="col-sm-8 <?php if ($fehler["nachricht"] != "") { echo 'error'; } ?>">
					<label class="control-label textarea-label" for="border-right9"><i  id="message-icon" class="fa fa-comment"></i></label>
					<textarea <?php if($cfg['HTML5_FEHLERMELDUNGEN']) { ?> required <?php }else{ ?> onchange="checkField(this)" <?php } ?> aria-label="Nachricht" name="nachricht" class="field" rows="5" placeholder="Nachricht *" style="box-shadow: 0 0 1px rgba(0,0,0, .4);height:100%;width:100%;" id="border-right9"><?php echo $_POST[nachricht]; ?></textarea>
					<?php if ($fehler["nachricht"] != "") { echo $fehler["nachricht"]; } ?>
				</div>
			</div>




		<?php
		// -------------------- DATEIUPLOAD START ----------------------
			if(0<$cfg['NUM_ATTACHMENT_FIELDS']){
				echo '<div class="row upload-row" style="background-image: url(/src/img/border-right.png);background-position: 2.85rem center;-webkit-text-size-adjust:none;background-repeat: no-repeat;">
						<div class="col-sm-8">
							<label class="control-label" for="upload_field"><i id="fileupload-icon" class="fa fa-download"></i></label>';
				for ($i=0; $i < $cfg['NUM_ATTACHMENT_FIELDS']; $i++) {
							echo '<input aria-label="Dateiupload" type="file" size=12 name="f[]" id="upload_field"/>';
				}
				echo '	</div>
					</div>';
			}
		// -------------------- DATEIUPLOAD ENDE ----------------------
		?>






		<?php
		// -------------------- SPAMPROTECTION START ----------------------

		if($cfg['Honeypot']){ ?>
			<div style="height: 2px; overflow: hidden;">
				<label style="margin-top: 10px;">Das nachfolgende Feld muss leer bleiben, damit die Nachricht gesendet wird!</label>
				<div style="margin-top: 10px;"><input type="email" name="mail" value="" /></div>
			</div>
		<?php }

		if($cfg['Zeitsperre']){ ?>
			<input type="hidden" name="chkspmtm" value="<?php echo time(); ?>" />
		<?php }

		if($cfg['Klick-Check']){ ?>
			<input type="hidden" name="chkspmkc" value="chkspmbt" />
		<?php }


		if($cfg['Sicherheitscode']) { ?>
			<div class="row captcha-row <?php if ($fehler["captcha"] != "") { echo 'error_container'; } ?>" style="background-image: url('/src/img/border-right.png');background-position: 2.85rem center;-webkit-text-size-adjust:none;background-repeat: no-repeat;">
				<div class="col-sm-8 <?php if ($fehler["captcha"] != "") { echo 'error'; } ?>">
					<label class="control-label" for="answer2"><i id="securitycode-icon" class="fa fa fa-unlock-alt"></i></label>
					<div>
						<img aria-label="Captcha" src="captcha/captcha.php" alt="Sicherheitscode" title="kontaktformular.com-sicherheitscode" id="captcha" />
						<a href="javascript:void(0);" onclick="javascript:document.getElementById('captcha').src='captcha/captcha.php?'+Math.random();cursor:pointer;">
							<span class="captchareload"><i style="color:grey;" class="fas fa-sync-alt"></i></span>
						</a>
					</div>
					<input <?php if($cfg['HTML5_FEHLERMELDUNGEN']) { ?> required style="box-shadow: 0 0 1px rgba(0,0,0, .4);" <?php }else{ ?> onchange="checkField(this)" <?php } ?> aria-label="Eingabe" id="answer2" placeholder="Sicherheitscode eingeben *" type="text" name="sicherheitscode" maxlength="150"  class="field<?php if ($fehler["captcha"] != "") { echo ' errordesignfields'; } ?>" />
					<?php if ($fehler["captcha"] != "") { echo $fehler["captcha"]; } ?>
				</div>
			</div>
		  

		<?php }

		if($cfg['Sicherheitsfrage']) { ?>
		  
			<div class="row question-row <?php if ($fehler["q_id12"] != "") { echo 'error_container'; } ?>" style="background-image: url('/src/img/border-right.png');background-position: 2.85rem center;-webkit-text-size-adjust:none;background-repeat: no-repeat;">
				<div class="col-sm-8 <?php if ($fehler["q_id12"] != "") { echo 'error'; } ?>">
					<label class="control-label" for="answer"><i id="securitycode-icon" class="fa fa fa-unlock-alt"></i></label>
					<div aria-label="Sicherheitsfrage">
						<?php echo $q[1]; ?>
						<input type="hidden" name="q_id" value="<?php echo $q[0]; ?>"/>
					</div>					
					<input <?php if($cfg['HTML5_FEHLERMELDUNGEN']) { ?> required style="box-shadow: 0 0 1px rgba(0,0,0, .4);" <?php }else{ ?> onchange="checkField(this)" <?php } ?> aria-label="Antwort" id="answer" placeholder="Sicherheitsfrage beantworten *" type="text" class="field<?php if ($fehler["q_id12"] != "") { echo ' errordesignfields'; } ?>" name="q" />
					<?php if ($fehler["q_id12"] != "") { echo $fehler["q_id12"]; } ?>
				</div>
			</div>
		  
		  

		<?php } 

		// -------------------- SPAMPROTECTION ENDE ----------------------
		{ ?>






		<?php }
		
		// -------------------- MAIL-COPY START ----------------------

		if(1==$cfg['Kopie_senden']) { ?>
			<div class="row checkbox-row" style="background-image: url('/src/img/border-right.png');background-position: 2.85rem center;-webkit-text-size-adjust:none;background-repeat: no-repeat;">
				<div class="col-sm-8">
					<label for="inlineCheckbox11" class="control-label"><i id="mailcopy-icon" class="fa fa-envelope"></i></label>
					<label class="checkbox-inline">
						<input aria-label="E-Mail-Kopie senden" type="checkbox" id="inlineCheckbox11" name="mail-copy" value="1" <?php if (isset($_POST['mail-copy']) && $_POST['mail-copy']=='1') echo(' checked="checked" '); ?>> <div style="padding-top:4px;padding-bottom:2px;"><span>Kopie der Nachricht per E-Mail senden</span></div>
					</label>
				</div>
			</div>
		<?php } 

		// -------------------- MAIL-COPY ENDE ----------------------
		
		
		// -------------------- DATAPROTECTION START ----------------------

		if($cfg['DATENSCHUTZ_ERKLAERUNG']) { ?>
			<div class="row checkbox-row <?php if ($fehler["datenschutz"] != "") { echo 'error_container'; } ?>" style="background-image: url('/src/img/border-right.png');background-position: 2.85rem center;-webkit-text-size-adjust:none;background-repeat: no-repeat;">
				<div class="col-sm-8 <?php if ($fehler["datenschutz"] != "") { echo 'error'; } ?>">
					<label for="inlineCheckbox12" class="control-label"><i id="dataprotection-icon" class="fas fa-user-shield "></i></label>
					<label class="checkbox-inline">
						<input <?php if($cfg['HTML5_FEHLERMELDUNGEN']) { ?> required style="box-shadow: 0 0 1px rgba(0,0,0, .4);" <?php }else{ ?> onchange="checkField(this)" <?php } ?> aria-label="Datenschutz" type="checkbox" id="inlineCheckbox12" name="datenschutz" value="akzeptiert" <?php if ($_POST['datenschutz']=='akzeptiert') echo(' checked="checked" '); ?>> <div style="padding-top:4px;padding-bottom:2px;"> <a href="<?php echo "$datenschutzerklaerung"; ?>" target="_blank">Ich stimme der Datenschutz&shy;erkl??rung zu.</a> *</div>
					</label>
					<?php if ($fehler["datenschutz"] != "") { echo $fehler["datenschutz"]; } ?>
				</div>
			</div>
		<?php } 

		// -------------------- DATAPROTECTION ENDE ----------------------
		 
		 ?>
		 
		 
<hr style="height:0.10rem; border:none; color:#DADADA; background-color:#DADADA; margin-top:40px; margin-bottom:35px;" />

			<div class="row" id="send">
				<div class="col-sm-8">
					
						<b>Hinweis:</b> Felder mit <span class="pflichtfeld">*</span> m??ssen ausgef??llt werden.
					<br />
					<br />
					<input type="submit" class="senden" name="kf-km" value="Senden"  />
					
					<div style="text-align:center;">
						<!-- Dieser Copyrighthinweis darf NICHT entfernt werden. --><br /><br /><a href="https://www.kontaktformular.com" title="kontaktformular.com" style="text-decoration: none;color:#000000;font-size:13px;" target="_blank">&copy; by kontaktformular.com - Alle Rechte vorbehalten.</a>
					</div>
				
				</div>
			</div>
		  
		  
		  
		<?php if($cfg['Klick-Check']){ ?>
			<script type="text/javascript">
				function chkspmkcfnk(){
					document.getElementsByName('chkspmkc')[0].value = 'chkspmhm';
				}
				document.getElementsByName('kf-km')[0].addEventListener('mouseenter', chkspmkcfnk);
				document.getElementsByName('kf-km')[0].addEventListener('touchstart', chkspmkcfnk);
			</script>
		<?php } ?>
			<script type="text/javascript">
				// set class kontaktformular-validate for form if user wants to send the form > so the invalid-styles only appears after validation
				function setValidationStyles(){
					document.getElementById('kontaktformular').setAttribute('class', 'kontaktformular kontaktformular-validate');
				}
				document.getElementsByName('kf-km')[0].addEventListener('click', setValidationStyles);
				document.getElementById('kontaktformular').addEventListener('submit', setValidationStyles);
			</script>
		<?php if(!$cfg['HTML5_FEHLERMELDUNGEN']) { ?>
			<script type="text/javascript">
				// set class kontaktformular-validate for form if user wants to send the form > so the invalid-styles only appears after validation
				function checkField(field){
					if(''!=field.value){
						
						// if field is checkbox: go to parentNode and do things because checkbox is in label-element
						if('checkbox'==field.getAttribute('type')){
							field.parentNode.parentNode.classList.remove("error");						
							field.parentNode.nextElementSibling.style.display = 'none';
						}
						// field is no checkbox: do things with field
						else{
							field.parentNode.classList.remove("error");
							field.nextElementSibling.style.display = 'none';
						}
						
						// remove class error_container from parent-elements
						field.parentNode.parentNode.parentNode.classList.remove("error_container");
						field.parentNode.parentNode.classList.remove("error_container");
						field.parentNode.classList.remove("error_container");	
					}
				}
			</script>
		<?php } ?>
		</form>
	</div>
</body>
</html>
