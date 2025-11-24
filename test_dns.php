<?php
header('Content-Type: text/plain');

$host = 'db.fbuzapvujmjecrnhbzuc.supabase.co';
echo "Testing DNS resolution for: $host\n\n";

echo "1. gethostbyname:\n";
$ip = gethostbyname($host);
echo "Result: $ip\n";
if ($ip === $host) {
    echo "FAILED: Returned hostname\n";
} else {
    echo "SUCCESS: Resolved to $ip\n";
}
echo "\n";

echo "2. dns_get_record (A):\n";
$records = dns_get_record($host, DNS_A);
print_r($records);
echo "\n";

echo "3. dns_get_record (AAAA):\n";
$records_v6 = dns_get_record($host, DNS_AAAA);
print_r($records_v6);
echo "\n";

echo "4. getaddrinfo (if available):\n";
// PHP doesn't have direct getaddrinfo, but we can simulate check
echo "Skipped\n";

echo "\nDone.";
