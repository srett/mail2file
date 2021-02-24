# mail2file
**mail2file** is a simple, quick and dirty "fake" SMTP (and POP3) server, intended to be used to create a file dump, or automate processing of those attachments. It was tested on PHP 7.3 and 7.4.

## what.
**mail2file** implements just enough SMTP to fool mail clients (and other SMTP servers) to deliver mail to it. You can write simple filter rules the will decide if and where to store any attachments received, or what process to execute that will process the received attachment further.

Attachments are written to disk as they are received, so memory consumption should stay low even when receiving potentially huge files.

### but why?
Sometimes, mail is still the easiest way to get a file from A to B, even in 2021.

## config + setup
`config.php` needs to go into the root directory, a template can be found in `stuff/`. Here you can set the FQDN the SMTP and POP3 servers identify with, and the ports to listen on. To use TLS, put the certificate + chain + private key into a file called `server.pem` into the root directory. For implicit TLD add an `ssl://` socket to the config, STARTTLS is supported automatically if `server.pem` exists.
The sample config contains the officially assigned ports + 10,000, since non-root users usually can't listen on ports < 1024, so you need to add some iptables rules to make this work, or similar.

## filters
Filters go into the `rules.d/` directory and are simple PHP snippets. See the `stuff/` directory for a set of examples. Note that processing doesn't end if a filter matches, so you can check the second parameter to your filter to see how many filters already matched.

## POP3 server???
There are basically two ways to use this. You can use whatever mail account is configured on your device and just send mail to your **mail2file** instance, given that port 25 is reachable and you have a DNS entry set up. But what if you don't want to relay your attachments through another mail server? What if you're sending mail from the same LAN the server is hosted on? It would be rather stupid to relay huge attachments to some mail server on the internet and then back home. To use the **mail2file** server directly, you need to configure a new mail account in you mail client, directly using your **mail2file** instance. But most mail clients want a way to *retrieve* mail too if you set up an account, and ask for a IMAP or POP3 server. So **mail2file** contains an even dumber POP3 server that is just good enough to satisfy your average mail client by pretending you have a mailbox that's completely empty. So you set up your mail client with your **mail2file** host name as SMTP and POP3 servers, using any credentials you want, and you're ready to go.
I'm using it at home this way, with a Let's Encrypt cert and then a dnsmasq entry for the dyndns hostname that points to the server's LAN IP address, so I can reach it properly from within my home network.

## open relay! danger! you're using PHP so you don't know what you're doing!
No U! **mail2file** doesn't contain any code to *send* email, so I'd like to see how you can abuse this to send spam. I guess using the exec filter you can actually turn this into a *sending* SMTP server, as well as a dozen other security nightmares, but that's a whole different story. As it stands, the RCPT TO address is just metadata to **mail2file** which can be evaluated in filter rules.

## but really, security
Use this wisely. By using a scripting language a couple of bug classes have been ruled out from the start, but in general try to think first when using this, especially the exec filter. Don't run it as root on a system that hasn't been patched for three years.
Add a new user for it, use the sample systemd service file from `stuff/` as a starting point.
This has been cobbled together in a weekend; I actually spent two more weekends implementing the configurable filter system, as the first version had everything hard-coded, which got annoying fast.

## TODO
Too much to write down right now.
