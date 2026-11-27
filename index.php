<?php
$file = __DIR__ . '/data.json';
$wallpaperDir = __DIR__ . '/wallpapers';
if (!is_dir($wallpaperDir)) {
    @mkdir($wallpaperDir, 0777, true);
}

$today = date('Y-m-d');

$data = [
    'ewallet' => 120000,
    'cash' => 50000,
    'budget' => 25000,
    'notif_enabled' => true,
    'sound_enabled' => true,
    'reminder_hour' => 23,
    'reminder_minute' => 55,
    'streak_days' => 1,
    'last_active_date' => $today,
    'theme' => [
        'wallpaper_preset' => 'bestie_candy',
        'custom_wallpaper' => '',
        'font_family' => 'sans',
        'font_size' => 15,
        'text_color' => '#1e1b4b',
        'bubble_style' => 'gradient',
        'user_bubble_bg' => '#ede9fe',
        'bot_bubble_bg' => '#ffffff'
    ],
    'expenses' => [],
    'chats' => [
        [
            'sender' => 'bot',
            'time' => date('H:i'),
            'text' => "Hai bestie! Angka minus, tombol chip bawah, dan rincian Rekap udah dirapihin—yuk lanjut catat jajan ✨",
            'card' => null
        ]
    ]
];

if (file_exists($file)) {
    $loaded = json_decode(file_get_contents($file), true);
    if (is_array($loaded)) {
        $data = array_merge($data, $loaded);
        if (!isset($data['ewallet'])) {
            $data['ewallet'] = (int) ($loaded['balance'] ?? 120000);
        }
        if (!isset($data['cash'])) {
            $data['cash'] = 0;
        }
        $data['theme'] = array_merge([
            'wallpaper_preset' => 'bestie_candy',
            'custom_wallpaper' => '',
            'font_family' => 'sans',
            'font_size' => 15,
            'text_color' => '#1e1b4b',
            'bubble_style' => 'gradient',
            'user_bubble_bg' => '#ede9fe',
            'bot_bubble_bg' => '#ffffff'
        ], is_array($loaded['theme'] ?? null) ? $loaded['theme'] : []);

        $oldUserBg = strtolower((string) ($data['theme']['user_bubble_bg'] ?? ''));
        if (in_array($oldUserBg, ['#dc2626', '#ef4444', '#991b1b', '#b91c1c', '#ff0000'], true)) {
            $data['theme']['user_bubble_bg'] = '#ede9fe';
        }

        if (($data['theme']['font_family'] ?? 'sans') === 'mono') {
            $data['theme']['font_family'] = 'sans';
        }

        if (!isset($data['expenses']) || !is_array($data['expenses'])) {
            $data['expenses'] = [];
        }
        if (!isset($data['chats']) || !is_array($data['chats'])) {
            $data['chats'] = [];
        }
    }
}

$lastActive = $data['last_active_date'] ?? $today;
$dayRolloverHappened = false;
if ($lastActive !== $today) {
    $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
    $data['streak_days'] = ($lastActive === $yesterday) ? ((int) ($data['streak_days'] ?? 0) + 1) : 1;
    $data['last_active_date'] = $today;
    $dayRolloverHappened = true;
}

function rupiah(int $n): string {
    $prefix = $n < 0 ? '-Rp' : 'Rp';
    return $prefix . number_format(abs($n), 0, ',', '.');
}

function rpHtml(int $n): string {
    return '<span class="nowrap-num">' . htmlspecialchars(rupiah($n), ENT_QUOTES, 'UTF-8') . '</span>';
}

function parseNominal(string $text): int {
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*(rb|ribu|k)\b/i', $text, $m)) {
        $num = (float) str_replace(',', '.', $m[1]);
        return (int) round($num * 1000);
    }
    if (preg_match('/rp\s*([0-9][0-9.,]*)/i', $text, $m)) {
        $cleaned = preg_replace('/[^0-9]/', '', $m[1]);
        return $cleaned !== '' ? (int) $cleaned : 0;
    }
    $cleaned = preg_replace('/[^0-9]/', '', $text);
    return $cleaned !== '' ? (int) $cleaned : 0;
}

function sanitizeHex(string $hex, string $fallback): string {
    $hex = trim($hex);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
        return $hex;
    }
    return $fallback;
}

function getSummary(array $data, string $today): array {
    $todayExpenses = [];
    foreach (($data['expenses'] ?? []) as $idx => $item) {
        if (($item['date'] ?? '') === $today) {
            $item['_idx'] = $idx;
            $todayExpenses[] = $item;
        }
    }
    $spentToday = (int) array_sum(array_column($todayExpenses, 'amount'));
    $budget = max(0, (int) ($data['budget'] ?? 0));
    $ewallet = (int) ($data['ewallet'] ?? 0);
    $cash = (int) ($data['cash'] ?? 0);
    $remainingBudget = $budget - $spentToday;
    $pct = $budget > 0 ? (int) round(($spentToday / $budget) * 100) : 0;
    return [$budget, $spentToday, $remainingBudget, $ewallet, $cash, $pct, $todayExpenses];
}

function buildDayChangeMessage(int $budget, int $remainingBudget, int $ewallet, int $cash): array {
    $body = "Tring! Hari baru dimulai. Budget harianmu " . rupiah($budget) . ", sisa budgetmu hari ini " . rupiah($remainingBudget) . " ya.";
    $chatText = "Tring! Info pergantian hari bestie 🌅\nBudget harianmu " . rupiah($budget) . " dan sisa budgetmu hari ini " . rupiah($remainingBudget) . " ya.";
    $card = [
        'badge' => 'GANTI HARI',
        'title' => 'Budget ' . rupiah($budget) . ' • Sisa ' . rupiah($remainingBudget),
        'lines' => [
            'Budget harian kamu: ' . rupiah($budget),
            'Sisa budget hari ini: ' . rupiah($remainingBudget),
            'E-wallet: ' . rupiah($ewallet) . ' • Cash: ' . rupiah($cash)
        ]
    ];
    return [$body, $chatText, $card];
}

$notifPayload = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $data['budget'] = max(0, (int) preg_replace('/[^0-9]/', '', $_POST['budget_input'] ?? (string) $data['budget']));
        $data['ewallet'] = (int) preg_replace('/[^0-9-]/', '', $_POST['ewallet_input'] ?? (string) $data['ewallet']);
        $data['cash'] = (int) preg_replace('/[^0-9-]/', '', $_POST['cash_input'] ?? (string) $data['cash']);
        $data['notif_enabled'] = !empty($_POST['notif_enabled_input']);
        $data['sound_enabled'] = !empty($_POST['sound_enabled_input']);
        $data['reminder_hour'] = max(0, min(23, (int) ($_POST['reminder_hour'] ?? 23)));
        $data['reminder_minute'] = max(0, min(59, (int) ($_POST['reminder_minute'] ?? 55)));

        $allowedWallpapers = ['bestie_candy', 'mint_boba', 'sunny_peach', 'lavender_pop', 'night_neon', 'custom'];
        $wpPreset = $_POST['wallpaper_preset'] ?? 'bestie_candy';
        if (!in_array($wpPreset, $allowedWallpapers, true)) {
            $wpPreset = 'bestie_candy';
        }

        if (!empty($_FILES['wallpaper_file']['name']) && is_uploaded_file($_FILES['wallpaper_file']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['wallpaper_file']['name'], PATHINFO_EXTENSION));
            $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            if (in_array($ext, $allowedExt, true)) {
                $fname = 'wp_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                if (move_uploaded_file($_FILES['wallpaper_file']['tmp_name'], $wallpaperDir . '/' . $fname)) {
                    $data['theme']['custom_wallpaper'] = 'wallpapers/' . $fname;
                    $wpPreset = 'custom';
                }
            }
        }

        $allowedFonts = ['sans', 'rounded', 'serif'];
        $fontFamily = $_POST['font_family'] ?? 'sans';
        if (!in_array($fontFamily, $allowedFonts, true)) {
            $fontFamily = 'sans';
        }

        $allowedBubbles = ['gradient', 'pill', 'classic', 'sharp'];
        $bubbleStyle = $_POST['bubble_style'] ?? 'gradient';
        if (!in_array($bubbleStyle, $allowedBubbles, true)) {
            $bubbleStyle = 'gradient';
        }

        $data['theme']['wallpaper_preset'] = $wpPreset;
        $data['theme']['font_family'] = $fontFamily;
        $data['theme']['font_size'] = max(12, min(22, (int) ($_POST['font_size'] ?? 15)));
        $data['theme']['text_color'] = sanitizeHex($_POST['text_color'] ?? '#1e1b4b', '#1e1b4b');
        $data['theme']['bubble_style'] = $bubbleStyle;
        $data['theme']['user_bubble_bg'] = sanitizeHex($_POST['user_bubble_bg'] ?? '#ede9fe', '#ede9fe');
        $data['theme']['bot_bubble_bg'] = sanitizeHex($_POST['bot_bubble_bg'] ?? '#ffffff', '#ffffff');

        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
        header('Location: index.php');
        exit;
    }

    if ($action === 'toggle_notif') {
        $data['notif_enabled'] = empty($data['notif_enabled']);
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
        header('Location: index.php');
        exit;
    }

    if ($action === 'delete_expense') {
        $delIdx = (int) ($_POST['expense_idx'] ?? -1);
        if (isset($data['expenses'][$delIdx]) && is_array($data['expenses'][$delIdx])) {
            $target = $data['expenses'][$delIdx];
            $amt = (int) ($target['amount'] ?? 0);
            $ch = ($target['channel'] ?? 'cash') === 'ewallet' ? 'ewallet' : 'cash';
            $noteDel = $target['note'] ?? 'jajan';
            array_splice($data['expenses'], $delIdx, 1);
            $data[$ch] = (int) ($data[$ch] ?? 0) + $amt;
            [$budget, $spentToday, $remainingBudget, $ewallet, $cash, $pct] = getSummary($data, $today);
            $data['chats'][] = [
                'sender' => 'bot',
                'time' => date('H:i'),
                'text' => "Item \"" . $noteDel . " (" . rupiah($amt) . ")\" dihapus dari riwayat & saldo dikembalikan.",
                'card' => [
                    'badge' => 'ITEM DIHAPUS',
                    'title' => 'Saldo ' . ($ch === 'ewallet' ? 'E-Wallet' : 'Cash') . ' +' . rupiah($amt),
                    'lines' => [
                        'Terpakai: ' . rupiah($spentToday) . ' • Sisa budget: ' . rupiah($remainingBudget),
                        'E-wallet: ' . rupiah($ewallet) . ' • Cash: ' . rupiah($cash)
                    ]
                ]
            ];
            file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
            $notifPayload = [
                'title' => 'Tring! Item Dihapus',
                'body' => 'Budgetmu ' . rupiah($budget) . ', sisa budgetmu hari ini ' . rupiah($remainingBudget) . ' ya.'
            ];
        }
        header('Location: index.php');
        exit;
    }

    if ($action === 'undo_last') {
        $last = array_pop($data['expenses']);
        if (is_array($last)) {
            $amt = (int) ($last['amount'] ?? 0);
            $ch = ($last['channel'] ?? 'cash') === 'ewallet' ? 'ewallet' : 'cash';
            $data[$ch] = (int) ($data[$ch] ?? 0) + $amt;
            [$budget, $spentToday, $remainingBudget, $ewallet, $cash, $pct] = getSummary($data, $today);
            $noteLast = $last['note'] ?? 'jajan';
            $data['chats'][] = [
                'sender' => 'bot',
                'time' => date('H:i'),
                'text' => "Aman, transaksi terakhir (" . $noteLast . " " . rupiah($amt) . ") sudah dibatalkan!",
                'card' => [
                    'badge' => 'DIBATALKAN',
                    'title' => 'Saldo ' . ($ch === 'ewallet' ? 'E-Wallet' : 'Cash') . ' dikembalikan +' . rupiah($amt),
                    'lines' => [
                        'Terpakai: ' . rupiah($spentToday) . ' • Sisa budget: ' . rupiah($remainingBudget),
                        'E-wallet: ' . rupiah($ewallet) . ' • Cash: ' . rupiah($cash)
                    ]
                ]
            ];
            file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
            $notifPayload = [
                'title' => 'Tring! Transaksi Dibatalkan',
                'body' => 'Budgetmu ' . rupiah($budget) . ', sisa budgetmu hari ini ' . rupiah($remainingBudget) . ' ya.'
            ];
        }
        header('Location: index.php');
        exit;
    }

    if ($action === 'reset_chat') {
        $data['chats'] = [];
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
        header('Location: index.php');
        exit;
    }

    if ($action === 'test_day_change') {
        [$budget, $spentToday, $remainingBudget, $ewallet, $cash, $pct] = getSummary($data, $today);
        [$dayBody, $dayChatText, $dayCard] = buildDayChangeMessage($budget, $remainingBudget, $ewallet, $cash);
        $data['chats'][] = [
            'sender' => 'bot',
            'time' => date('H:i'),
            'text' => $dayChatText,
            'card' => $dayCard
        ];
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
        $notifPayload = [
            'title' => 'Tring! Info Pergantian Hari',
            'body' => $dayBody
        ];
    } elseif ($action === 'chat') {
        $msg = trim($_POST['message'] ?? '');
        if ($msg !== '') {
            $nowTime = date('H:i');
            $data['chats'][] = [
                'sender' => 'user',
                'time' => $nowTime,
                'text' => $msg,
                'card' => null
            ];

            $lower = strtolower($msg);
            $amount = parseNominal($msg);

            $mentionsCash = preg_match('/\b(cash|tunai|kontan)\b/i', $lower) === 1;
            $mentionsEwallet = preg_match('/\b(e-wallet|ewallet|wallet|dana|ovo|gopay|shopeepay|saldo|rekening)\b/i', $lower) === 1;
            $isTransfer = preg_match('/\b(topup|top up|pindah|transfer|isi saldo)\b/i', $lower) === 1 && ($mentionsCash || $mentionsEwallet);
            $isIncome = !$isTransfer && (preg_match('/\b(pemasukan|masuk|gajian|dapat|punya uang|kiriman)\b/i', $lower) === 1 || (($mentionsCash || $mentionsEwallet) && !preg_match('/\b(jajan|beli|bayar|keluar|pakai|makan|minum|kopi|seblak|bakso)\b/i', $lower) && $amount > 0));
            $isBudget = str_contains($lower, 'budget');
            $isAsk = preg_match('/\b(sisa|cek|rekap|saldo|cash|budget|berapa)\b/i', $lower) === 1 && $amount === 0;

            $card = null;
            $notifTitle = null;
            $notifBody = null;

            if ($isBudget && $amount > 0) {
                $data['budget'] = $amount;
                [$budget, $spentToday, $remainingBudget, $ewallet, $cash, $pct] = getSummary($data, $today);
                $reply = "Sippp! Budget harian di-set ke " . rupiah($budget) . ". Sisa budgetmu hari ini " . rupiah($remainingBudget) . " ya ✨";
                $card = [
                    'badge' => 'BUDGET BARU',
                    'title' => 'Budget ' . rupiah($budget) . ' / hari',
                    'lines' => [
                        'Terpakai hari ini: ' . rupiah($spentToday) . ' • Sisa: ' . rupiah($remainingBudget),
                        'E-wallet: ' . rupiah($ewallet) . ' • Cash: ' . rupiah($cash)
                    ]
                ];
                $notifTitle = "Tring! Budgetmu " . rupiah($budget);
                $notifBody = "Budget harianmu " . rupiah($budget) . ", sisa budgetmu hari ini " . rupiah($remainingBudget) . " ya.";
            } elseif ($isTransfer && $amount > 0) {
                if ($mentionsEwallet && str_contains($lower, 'dari cash')) {
                    $data['cash'] = (int) ($data['cash'] ?? 0) - $amount;
                    $data['ewallet'] = (int) ($data['ewallet'] ?? 0) + $amount;
                    $routeText = 'Cash → E-Wallet';
                } elseif ($mentionsCash && (str_contains($lower, 'dari e-wallet') || str_contains($lower, 'dari ewallet') || str_contains($lower, 'tarik'))) {
                    $data['ewallet'] = (int) ($data['ewallet'] ?? 0) - $amount;
                    $data['cash'] = (int) ($data['cash'] ?? 0) + $amount;
                    $routeText = 'E-Wallet → Cash';
                } elseif ($mentionsEwallet) {
                    $data['cash'] = (int) ($data['cash'] ?? 0) - $amount;
                    $data['ewallet'] = (int) ($data['ewallet'] ?? 0) + $amount;
                    $routeText = 'Cash → E-Wallet';
                } else {
                    $data['ewallet'] = (int) ($data['ewallet'] ?? 0) - $amount;
                    $data['cash'] = (int) ($data['cash'] ?? 0) + $amount;
                    $routeText = 'E-Wallet → Cash';
                }
                [$budget, $spentToday, $remainingBudget, $ewallet, $cash, $pct] = getSummary($data, $today);
                $reply = "Dompet dipindah (" . $routeText . ") sebesar " . rupiah($amount) . ". Ini cuma pindah pos, bukan pengeluaran baru ya 😉";
                $card = [
                    'badge' => 'PINDAH DOMPET',
                    'title' => $routeText . ' • ' . rupiah($amount),
                    'lines' => [
                        'Budget harian: ' . rupiah($budget) . ' • Sisa hari ini: ' . rupiah($remainingBudget),
                        'E-wallet: ' . rupiah($ewallet) . ' • Cash: ' . rupiah($cash)
                    ]
                ];
                $notifTitle = "Tring! " . $routeText . " " . rupiah($amount);
                $notifBody = "Budgetmu " . rupiah($budget) . ", sisa budgetmu hari ini " . rupiah($remainingBudget) . " ya.";
            } elseif ($isIncome && $amount > 0) {
                if ($mentionsCash && !$mentionsEwallet) {
                    $data['cash'] = (int) ($data['cash'] ?? 0) + $amount;
                    $channelLabel = 'Cash';
                } else {
                    $data['ewallet'] = (int) ($data['ewallet'] ?? 0) + $amount;
                    $channelLabel = 'E-Wallet';
                }
                [$budget, $spentToday, $remainingBudget, $ewallet, $cash, $pct] = getSummary($data, $today);
                $reply = "Yeay, pemasukan " . $channelLabel . " +" . rupiah($amount) . " masuk! Lumayan banget buat napas dompet 🎉";
                $card = [
                    'badge' => 'PEMASUKAN',
                    'title' => '+' . rupiah($amount) . ' (' . $channelLabel . ')',
                    'lines' => [
                        'Budget harian: ' . rupiah($budget) . ' • Sisa hari ini: ' . rupiah($remainingBudget),
                        'E-wallet: ' . rupiah($ewallet) . ' • Cash: ' . rupiah($cash)
                    ]
                ];
                $notifTitle = "Tring! +" . rupiah($amount) . " ke " . $channelLabel;
                $notifBody = "Budgetmu " . rupiah($budget) . ", sisa budgetmu hari ini " . rupiah($remainingBudget) . " ya.";
            } elseif ($isAsk) {
                [$budget, $spentToday, $remainingBudget, $ewallet, $cash, $pct] = getSummary($data, $today);
                if ($remainingBudget < -20000) {
                    $vibeNote = "Jujurly hari ini udah jebol lumayan jauh (" . rupiah(abs($remainingBudget)) . " lewat budget). Yuk ngerem dulu biar sisa hari aman 🙏";
                } elseif ($remainingBudget < 0) {
                    $vibeNote = "Udah lewat budget dikit nih bestie—kita rem pelan-pelan mulai sekarang ya 💕";
                } elseif ($pct >= 75) {
                    $vibeNote = "Budget udah kepakai " . $pct . "%, pilih yang beneran penting aja ya ✨";
                } else {
                    $vibeNote = "Masih aman banget! Kamu on track hari ini 🌟";
                }
                $reply = $vibeNote;
                $card = [
                    'badge' => 'RINGKASAN HARI INI',
                    'title' => 'Budget ' . rupiah($budget) . ' • Sisa ' . rupiah($remainingBudget),
                    'lines' => [
                        'Terpakai hari ini: ' . rupiah($spentToday) . ' (' . $pct . '%)',
                        'E-wallet: ' . rupiah($ewallet) . ' • Cash: ' . rupiah($cash)
                    ]
                ];
                $notifTitle = "Tring! Sisa Budgetmu Hari Ini " . rupiah($remainingBudget);
                $notifBody = "Budget harianmu " . rupiah($budget) . ", sisa budgetmu hari ini " . rupiah($remainingBudget) . " ya.";
            } elseif ($amount > 0) {
                if ($mentionsCash && !$mentionsEwallet) {
                    $data['cash'] = (int) ($data['cash'] ?? 0) - $amount;
                    $channel = 'cash';
                } elseif ($mentionsEwallet && !$mentionsCash) {
                    $data['ewallet'] = (int) ($data['ewallet'] ?? 0) - $amount;
                    $channel = 'ewallet';
                } else {
                    if (((int) ($data['cash'] ?? 0)) >= $amount) {
                        $data['cash'] = (int) ($data['cash'] ?? 0) - $amount;
                        $channel = 'cash';
                    } else {
                        $data['ewallet'] = (int) ($data['ewallet'] ?? 0) - $amount;
                        $channel = 'ewallet';
                    }
                }

                $note = preg_replace('/\b\d+(?:[.,]\d+)?\s*(rb|ribu|k)?\b/i', '', $msg);
                $note = preg_replace('/\b(jajan|beli|bayar|buat|rp|pagi|siang|malam|cash|tunai|e-wallet|ewallet|wallet)\b/i', '', $note);
                $note = trim(preg_replace('/\s+/', ' ', (string) $note));
                if ($note === '') {
                    $note = 'jajan';
                }

                $data['expenses'][] = [
                    'date' => $today,
                    'time' => $nowTime,
                    'amount' => $amount,
                    'channel' => $channel,
                    'note' => $note
                ];

                [$budget, $spentToday, $remainingBudget, $ewallet, $cash, $pct] = getSummary($data, $today);

                if ($remainingBudget < -20000) {
                    $reply = "Tercatat " . $note . " " . rupiah($amount) . ". Wah, budget hari ini udah lewat " . rupiah(abs($remainingBudget)) . "—wajib ngerem dulu ya bestie, aku tetap dukung biar besok lebih rapi!";
                    $badge = 'JEBOL BUDGET';
                } elseif ($remainingBudget < 0) {
                    $reply = "Tercatat " . $note . " " . rupiah($amount) . ". Udah lewat budget " . rupiah(abs($remainingBudget)) . " nih—kita tahan dulu jajan tambahan setelah ini ya!";
                    $badge = 'LEWAT BUDGET';
                } elseif ($pct >= 75) {
                    $reply = "Tercatat " . $note . " " . rupiah($amount) . ". Budget udah jalan " . $pct . "%, makin hati-hati di sisa hari ya 😉";
                    $badge = 'HAMPIR HABIS';
                } else {
                    $reply = "Tercatat " . $note . " " . rupiah($amount) . "! Masih aman dan terkendali banget ✨";
                    $badge = 'AMAN TERKENDALI';
                }

                $card = [
                    'badge' => $badge,
                    'title' => ucfirst($note) . ' • ' . rupiah($amount) . ' (' . ($channel === 'ewallet' ? 'E-Wallet' : 'Cash') . ')',
                    'lines' => [
                        'Budget: ' . rupiah($budget) . ' • Sisa hari ini: ' . rupiah($remainingBudget),
                        'E-wallet: ' . rupiah($ewallet) . ' • Cash: ' . rupiah($cash)
                    ]
                ];

                $notifTitle = "Tring! " . ucfirst($note) . " " . rupiah($amount);
                $notifBody = "Budgetmu " . rupiah($budget) . ", sisa budgetmu hari ini " . rupiah($remainingBudget) . " ya.";
            } else {
                $reply = "Aku belum ketemu angkanya nih bestie 😅 Ketik nominalnya atau tap tombol Nominal di bawah ya!";
                $notifTitle = "Tring! Pilih Nominal Dulu";
                $notifBody = "Kamu bilang \"" . $msg . "\" — pilih nominal cepat biar langsung tercatat.";
            }

            $data['chats'][] = [
                'sender' => 'bot',
                'time' => date('H:i'),
                'text' => $reply,
                'card' => $card
            ];

            file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));

            if (!empty($notifTitle) && !empty($notifBody)) {
                $notifPayload = [
                    'title' => $notifTitle,
                    'body' => $notifBody
                ];
            }
        }
    }
}

[$budget, $spentToday, $remainingBudget, $ewallet, $cash, $pct, $todayExpenses] = getSummary($data, $today);

if ($dayRolloverHappened && $notifPayload === null) {
    [$dayBody, $dayChatText, $dayCard] = buildDayChangeMessage($budget, $remainingBudget, $ewallet, $cash);
    $data['chats'][] = [
        'sender' => 'bot',
        'time' => date('H:i'),
        'text' => $dayChatText,
        'card' => $dayCard
    ];
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    $notifPayload = [
        'title' => 'Tring! Pergantian Hari',
        'body' => $dayBody
    ];
}

$notifEnabled = !empty($data['notif_enabled']);
$soundEnabled = !empty($data['sound_enabled']);
$remHour = (int) ($data['reminder_hour'] ?? 23);
$remMin = (int) ($data['reminder_minute'] ?? 55);
$streakDays = max(1, (int) ($data['streak_days'] ?? 1));
$theme = $data['theme'];

$validChats = array_values(array_filter(
    $data['chats'] ?? [],
    fn($c) => is_array($c) && (trim((string) ($c['text'] ?? '')) !== '' || !empty($c['card']))
));

$barPct = max(0, min(100, $pct));
if ($remainingBudget < 0) {
    $barColor = '#fda4af';
    $statusText = 'Lewat batas';
} elseif ($pct >= 75) {
    $barColor = '#fde047';
    $statusText = 'Menipis (' . $pct . '%)';
} else {
    $barColor = '#6ee7b7';
    $statusText = 'Aman (' . $pct . '%)';
}

$fontCssMap = [
    'sans' => "'Plus Jakarta Sans', 'Inter', 'Segoe UI', Roboto, Arial, sans-serif",
    'rounded' => "'Trebuchet MS', 'Nunito', Arial, sans-serif",
    'serif' => "Georgia, 'Times New Roman', serif"
];
$activeFontCss = $fontCssMap[$theme['font_family']] ?? $fontCssMap['sans'];

$wpStyle = "background: linear-gradient(155deg, #fdf2f8 0%, #ede9fe 50%, #e0f2fe 100%);";
if ($theme['wallpaper_preset'] === 'mint_boba') {
    $wpStyle = "background: linear-gradient(155deg, #d1fae5 0%, #ccfbf1 55%, #e0f2fe 100%);";
} elseif ($theme['wallpaper_preset'] === 'sunny_peach') {
    $wpStyle = "background: linear-gradient(155deg, #ffedd5 0%, #fef3c7 55%, #ffe4e6 100%);";
} elseif ($theme['wallpaper_preset'] === 'lavender_pop') {
    $wpStyle = "background-color: #f5f3ff; background-image: radial-gradient(#c4b5fd 1.2px, transparent 1.2px); background-size: 20px 20px;";
} elseif ($theme['wallpaper_preset'] === 'night_neon') {
    $wpStyle = "background: linear-gradient(165deg, #1e1b4b 0%, #311042 55%, #0f172a 100%);";
} elseif ($theme['wallpaper_preset'] === 'custom' && !empty($theme['custom_wallpaper']) && file_exists(__DIR__ . '/' . $theme['custom_wallpaper'])) {
    $safeWp = htmlspecialchars($theme['custom_wallpaper'], ENT_QUOTES, 'UTF-8');
    $wpStyle = "background: url('{$safeWp}') center/cover no-repeat fixed;";
}

$bubbleStyle = $theme['bubble_style'];
$uBg = htmlspecialchars($theme['user_bubble_bg'], ENT_QUOTES, 'UTF-8');
$bBg = htmlspecialchars($theme['bot_bubble_bg'], ENT_QUOTES, 'UTF-8');

if ($bubbleStyle === 'pill') {
    $userBubbleCss = "background: {$uBg}; border-radius: 20px;";
    $botBubbleCss = "background: {$bBg}; border-radius: 20px;";
} elseif ($bubbleStyle === 'classic') {
    $userBubbleCss = "background: {$uBg}; border-radius: 14px; border-bottom-right-radius: 4px;";
    $botBubbleCss = "background: {$bBg}; border-radius: 14px; border-bottom-left-radius: 4px;";
} elseif ($bubbleStyle === 'sharp') {
    $userBubbleCss = "background: {$uBg}; border-radius: 10px; border-right: 4px solid #7c3aed;";
    $botBubbleCss = "background: {$bBg}; border-radius: 10px; border-left: 4px solid #ec4899;";
} else {
    $userBubbleCss = "background: linear-gradient(135deg, {$uBg}, #ffffff); border-radius: 18px;";
    $botBubbleCss = "background: linear-gradient(135deg, {$bBg}, #faf5ff); border-radius: 18px;";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Bestie Budget & Jajan</title>
<style>
  * { box-sizing: border-box; }
  html, body {
    margin: 0;
    padding: 0;
    height: 100%;
    overflow: hidden;
  }
  body {
    font-family: <?= $activeFontCss ?>;
    font-size: <?= (int) $theme['font_size'] ?>px;
    color: <?= htmlspecialchars($theme['text_color'], ENT_QUOTES, 'UTF-8') ?>;
    display: flex;
    flex-direction: column;
    font-variant-numeric: tabular-nums;
    <?= $wpStyle ?>
  }
  .topbar {
    background: linear-gradient(120deg, #6d28d9 0%, #be185d 100%);
    color: #fff;
    padding: 8px 10px;
    box-shadow: 0 3px 12px rgba(109, 40, 217, 0.2);
    flex-shrink: 0;
  }
  .topbar-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
  }
  .topbar-title {
    font-weight: 800;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
  }
  .streak-pill {
    background: rgba(255,255,255,0.22);
    padding: 2px 7px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap;
  }
  .nowrap-num { white-space: nowrap; font-weight: 700; }
  .topbar-sub {
    font-size: 11px;
    opacity: 0.96;
    margin-top: 4px;
    line-height: 1.35;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 8px;
  }
  .actions {
    display: flex;
    gap: 4px;
    align-items: center;
    flex-wrap: wrap;
  }
  .btn-chip {
    background: rgba(255,255,255,0.2);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.28);
    border-radius: 999px;
    padding: 4px 7px;
    font-size: 10px;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
  }
  .btn-chip:active { transform: scale(0.96); }
  .btn-chip.on { background: #fde047; color: #3b0764; border-color: #fde047; }
  .budget-meter { margin-top: 5px; }
  .meter-meta {
    display: flex;
    justify-content: space-between;
    font-size: 10.5px;
    margin-bottom: 3px;
    font-weight: 600;
  }
  .meter-track {
    width: 100%;
    height: 5px;
    background: rgba(255,255,255,0.24);
    border-radius: 999px;
    overflow: hidden;
  }
  .meter-fill {
    height: 100%;
    width: <?= $barPct ?>%;
    background: <?= $barColor ?>;
    border-radius: 999px;
    transition: width 0.3s ease;
  }
  .chat-area {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .empty-state {
    margin: auto;
    max-width: 340px;
    background: rgba(255,255,255,0.92);
    color: #1e1b4b;
    padding: 16px;
    border-radius: 18px;
    text-align: center;
    box-shadow: 0 6px 20px rgba(30,27,75,0.08);
  }
  .empty-state h4 { margin: 0 0 6px; font-size: 15px; }
  .empty-state p { margin: 0 0 12px; font-size: 12.5px; color: #4c1d95; line-height: 1.4; }
  .bubble {
    max-width: 85%;
    padding: 10px 13px;
    line-height: 1.45;
    white-space: pre-line;
    box-shadow: 0 2px 8px rgba(30,27,75,0.08);
    font-size: inherit;
    font-family: inherit;
    color: inherit;
  }
  .user { align-self: flex-end; <?= $userBubbleCss ?> }
  .bot { align-self: flex-start; <?= $botBubbleCss ?> }
  .mini-card {
    margin-top: 8px;
    padding: 8px 10px;
    border-radius: 12px;
    background: rgba(255,255,255,0.88);
    border: 1px solid rgba(109,40,217,0.16);
    font-size: 12px;
    color: #1e1b4b;
  }
  .card-badge {
    display: inline-block;
    font-size: 10px;
    font-weight: 800;
    padding: 2px 7px;
    border-radius: 999px;
    background: #ede9fe;
    color: #6d28d9;
    margin-bottom: 4px;
  }
  .card-title { font-weight: 700; font-size: 13px; margin-bottom: 3px; }
  .card-line { color: #334155; line-height: 1.4; }
  .time {
    display: block;
    font-size: 10.5px;
    opacity: 0.7;
    text-align: right;
    margin-top: 4px;
  }
  .bottom-dock {
    flex-shrink: 0;
    background: rgba(255,255,255,0.95);
    backdrop-filter: blur(10px);
    border-top: 1px solid rgba(109,40,217,0.12);
    padding-bottom: env(safe-area-inset-bottom, 4px);
    position: relative;
  }
  .amount-drawer {
    display: none;
    padding: 7px 10px 4px;
    gap: 6px;
    flex-wrap: wrap;
    background: #fdf4ff;
    border-bottom: 1px solid #f3e8ff;
  }
  .amount-drawer.open { display: flex; }
  .amt-chip {
    background: #fff;
    color: #be185d;
    border: 1px solid #f9a8d4;
    border-radius: 999px;
    padding: 4px 10px;
    font-size: 11.5px;
    font-weight: 700;
    cursor: pointer;
  }
  .amt-chip:active { background: #fce7f3; }
  .quick-chips {
    display: flex;
    gap: 4px;
    padding: 6px 6px 3px;
    flex-wrap: nowrap;
  }
  .q-chip {
    flex: 1 1 0;
    min-width: 0;
    text-align: center;
    white-space: nowrap;
    background: #fff;
    color: #5b21b6;
    border: 1px solid #ddd6fe;
    border-radius: 999px;
    padding: 5px 4px;
    font-size: 10.5px;
    font-weight: 700;
    cursor: pointer;
  }
  .q-chip:active { background: #f3e8ff; }
  .input-bar {
    padding: 4px 8px 8px;
    display: flex;
    gap: 7px;
    align-items: center;
  }
  .input-bar input {
    flex: 1;
    min-width: 0;
    padding: 10px 13px;
    border: 1.5px solid #e9d5ff;
    border-radius: 999px;
    font-size: 14.5px;
    outline: none;
    background: #fff;
    color: #1e1b4b;
  }
  .input-bar input:focus { border-color: #8b5cf6; }
  .input-bar button {
    background: linear-gradient(135deg, #7c3aed, #db2777);
    color: #fff;
    border: none;
    border-radius: 999px;
    padding: 0 15px;
    height: 42px;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    flex-shrink: 0;
  }
  .top-alert {
    display: none;
    position: fixed;
    top: 10px;
    left: 10px;
    right: 10px;
    background: #1e1b4b;
    color: #fff;
    padding: 11px 13px;
    border-radius: 14px;
    border-left: 4px solid #f472b6;
    font-size: 13px;
    z-index: 120;
    box-shadow: 0 8px 22px rgba(0,0,0,0.25);
  }
  .modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,0.55);
    z-index: 100;
    justify-content: center;
    align-items: center;
    padding: 12px;
  }
  .modal-backdrop.open { display: flex; }
  .modal-card {
    background: #fff;
    color: #1e1b4b;
    width: 100%;
    max-width: 440px;
    max-height: 88vh;
    overflow-y: auto;
    border-radius: 18px;
    padding: 16px;
    box-shadow: 0 12px 32px rgba(0,0,0,0.28);
  }
  .modal-card h3 { margin: 0 0 10px; font-size: 17px; }
  .info-note {
    background: #f5f3ff;
    border: 1px solid #ddd6fe;
    color: #4c1d95;
    font-size: 11.5px;
    padding: 8px 10px;
    border-radius: 10px;
    margin-bottom: 12px;
    line-height: 1.45;
  }
  .rekap-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    margin-bottom: 8px;
    font-size: 12.5px;
  }
  .btn-del {
    background: #fee2e2;
    color: #b91c1c;
    border: none;
    border-radius: 8px;
    padding: 5px 8px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
  }
  .field-group { margin-bottom: 12px; }
  .field-group label { display: block; font-size: 12.5px; font-weight: 700; margin-bottom: 5px; }
  .field-group input[type="number"],
  .field-group select,
  .field-group input[type="file"] {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    font-size: 14px;
  }
  .two-col { display: flex; gap: 8px; }
  .two-col > div { flex: 1; }
  .check-row { display: flex; gap: 14px; flex-wrap: wrap; font-size: 13px; }
  .color-row { display: flex; gap: 10px; }
  .color-row > div { flex: 1; }
  .color-row input[type="color"] { width: 100%; height: 36px; border: none; background: transparent; cursor: pointer; }
  .modal-actions { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
  .btn-save { flex: 1; background: linear-gradient(135deg, #7c3aed, #db2777); color: #fff; border: none; padding: 10px; border-radius: 10px; font-weight: 700; cursor: pointer; }
  .btn-cancel { background: #e2e8f0; color: #1e293b; border: none; padding: 10px 14px; border-radius: 10px; font-weight: 600; cursor: pointer; }
  .btn-soft-danger { background: #fff1f2; color: #be123c; border: 1px solid #fecdd3; padding: 9px 12px; border-radius: 10px; font-size: 12px; font-weight: 700; cursor: pointer; }
</style>
</head>
<body>
  <div id="topAlert" class="top-alert"></div>

  <div class="topbar">
    <div class="topbar-top">
      <div class="topbar-title">
        Bestie Budget
        <span class="streak-pill">🔥 <?= $streakDays ?> hari</span>
      </div>
      <div class="actions">
        <button class="btn-chip" type="button" onclick="openRekap()">📋 Rekap</button>
        <button class="btn-chip" type="button" onclick="openModal()">⚙️ Atur</button>
        <form method="POST" style="margin:0;">
          <input type="hidden" name="action" value="toggle_notif">
          <button class="btn-chip <?= $notifEnabled ? 'on' : '' ?>" type="submit">
            Notif: <?= $notifEnabled ? 'ON' : 'OFF' ?>
          </button>
        </form>
        <?php if (!empty($data['expenses'])): ?>
          <form method="POST" style="margin:0;">
            <input type="hidden" name="action" value="undo_last">
            <button class="btn-chip" type="submit">↩️ Batal</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="topbar-sub">
      <span>Sisa budget: <?= rpHtml($remainingBudget) ?>/<?= rpHtml($budget) ?></span>
      <span>•</span>
      <span>EW: <?= rpHtml($ewallet) ?></span>
      <span>•</span>
      <span>Cash: <?= rpHtml($cash) ?></span>
    </div>

    <div class="budget-meter">
      <div class="meter-meta">
        <span>Terpakai hari ini: <?= rpHtml($spentToday) ?></span>
        <span><?= $statusText ?></span>
      </div>
      <div class="meter-track">
        <div class="meter-fill"></div>
      </div>
    </div>
  </div>

  <div class="chat-area" id="chatArea">
    <?php if (empty($validChats)): ?>
      <div class="empty-state">
        <h4>Chat lagi bersih nih ✨</h4>
        <p>Budget harianmu <strong><?= rpHtml($budget) ?></strong>, sisa budgetmu hari ini <strong><?= rpHtml($remainingBudget) ?></strong>.</p>
        <button type="button" class="q-chip" onclick="quickSend('cek sisa')">📊 Cek Sisa</button>
      </div>
    <?php else: ?>
      <?php foreach ($validChats as $c): ?>
        <div class="bubble <?= ($c['sender'] ?? 'bot') === 'user' ? 'user' : 'bot' ?>">
          <?php if (trim((string) ($c['text'] ?? '')) !== ''): ?>
            <?= preg_replace('/(-?Rp[0-9.,]+)/', '<span class="nowrap-num">$1</span>', htmlspecialchars($c['text'])) ?>
          <?php endif; ?>
          <?php if (!empty($c['card']) && is_array($c['card'])): ?>
            <div class="mini-card">
              <span class="card-badge"><?= htmlspecialchars($c['card']['badge'] ?? 'INFO') ?></span>
              <div class="card-title"><?= preg_replace('/(-?Rp[0-9.,]+)/', '<span class="nowrap-num">$1</span>', htmlspecialchars($c['card']['title'] ?? '')) ?></div>
              <?php foreach (($c['card']['lines'] ?? []) as $line): ?>
                <div class="card-line">• <?= preg_replace('/(-?Rp[0-9.,]+)/', '<span class="nowrap-num">$1</span>', htmlspecialchars($line)) ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <span class="time"><?= htmlspecialchars($c['time'] ?? '') ?></span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="bottom-dock">
    <div class="amount-drawer" id="amountDrawer">
      <button type="button" class="amt-chip" onclick="pickAmount('3rb')">+3rb</button>
      <button type="button" class="amt-chip" onclick="pickAmount('5rb')">+5rb</button>
      <button type="button" class="amt-chip" onclick="pickAmount('10rb')">+10rb</button>
      <button type="button" class="amt-chip" onclick="pickAmount('15rb')">+15rb</button>
      <button type="button" class="amt-chip" onclick="pickAmount('20rb')">+20rb</button>
      <button type="button" class="amt-chip" onclick="pickAmount('25rb')">+25rb</button>
      <button type="button" class="amt-chip" onclick="pickAmount('50rb')">+50rb</button>
    </div>

    <div class="quick-chips" aria-label="Aksi cepat">
      <button type="button" class="q-chip" onclick="toggleAmounts()">💰 Nominal</button>
      <button type="button" class="q-chip" onclick="quickSend('jajan 5rb')">☕ Jajan 5rb</button>
      <button type="button" class="q-chip" onclick="quickSend('topup ewallet 20rb dari cash')">💳 Top-up</button>
      <button type="button" class="q-chip" onclick="quickSend('cek sisa')">📊 Cek Sisa</button>
    </div>

    <form class="input-bar" method="POST" id="chatForm">
      <input type="hidden" name="action" value="chat">
      <input type="text" name="message" id="msgInput" placeholder="Ketik pesan... misal: kopi 15rb" autocomplete="off" required>
      <button type="submit">Kirim</button>
    </form>
  </div>

  <div id="rekapModal" class="modal-backdrop">
    <div class="modal-card">
      <h3>Rekap Hari Ini (<?= htmlspecialchars($today) ?>)</h3>
      <div class="info-note">
        <strong>Batas Budget:</strong> <?= rpHtml($budget) ?> • <strong>Terpakai:</strong> <?= rpHtml($spentToday) ?> • <strong>Sisa Budget:</strong> <?= rpHtml($remainingBudget) ?><br>
        <strong>Dompet Aktif:</strong> EW <?= rpHtml($ewallet) ?> + Cash <?= rpHtml($cash) ?> = <?= rpHtml($ewallet + $cash) ?><br>
        <span style="opacity:0.85;">Catatan: <em>Terpakai</em> dihitung dari total transaksi hari ini (bisa melebihi budget), sedangkan <em>Dompet</em> adalah saldo uang riil yang kamu pegang.</span>
      </div>

      <?php if (empty($todayExpenses)): ?>
        <p style="font-size:13px; color:#64748b;">Belum ada pengeluaran tercatat hari ini.</p>
      <?php else: ?>
        <?php foreach ($todayExpenses as $exp): ?>
          <div class="rekap-item">
            <div>
              <strong><?= htmlspecialchars(ucfirst((string) ($exp['note'] ?? 'jajan'))) ?></strong>
              <div style="font-size:11px; color:#64748b;">
                <?= htmlspecialchars((string) ($exp['time'] ?? '')) ?> • <?= ($exp['channel'] ?? 'cash') === 'ewallet' ? 'E-Wallet' : 'Cash' ?>
              </div>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
              <?= rpHtml((int) ($exp['amount'] ?? 0)) ?>
              <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="delete_expense">
                <input type="hidden" name="expense_idx" value="<?= (int) $exp['_idx'] ?>">
                <button type="submit" class="btn-del">Hapus</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeRekap()">Tutup</button>
      </div>
    </div>
  </div>

  <div id="settingsModal" class="modal-backdrop">
    <div class="modal-card">
      <h3>Pengaturan & Gaya Bestie</h3>
      <div class="info-note">
        <strong>Info Ganti Hari:</strong> Menampilkan notifikasi + suara dengan budget & sisa budget hari ini tanpa menghapus saldo Cash/E-wallet maupun riwayat transaksi.
      </div>

      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_settings">

        <div class="field-group">
          <label>Budget Harian (Rp)</label>
          <input type="number" name="budget_input" min="0" step="500" value="<?= (int) $budget ?>" required>
        </div>

        <div class="field-group two-col">
          <div>
            <label>Saldo E-Wallet (Rp)</label>
            <input type="number" name="ewallet_input" step="500" value="<?= (int) $ewallet ?>" required>
          </div>
          <div>
            <label>Uang Cash (Rp)</label>
            <input type="number" name="cash_input" step="500" value="<?= (int) $cash ?>" required>
          </div>
        </div>

        <div class="field-group">
          <label>Notifikasi & Nada Dering</label>
          <div class="check-row">
            <label><input type="checkbox" name="notif_enabled_input" value="1" <?= $notifEnabled ? 'checked' : '' ?>> Aktifkan Notif</label>
            <label><input type="checkbox" name="sound_enabled_input" value="1" <?= $soundEnabled ? 'checked' : '' ?>> Nada Dering</label>
          </div>
        </div>

        <div class="field-group two-col">
          <div>
            <label>Jam Pengingat (0-23)</label>
            <input type="number" name="reminder_hour" min="0" max="23" value="<?= $remHour ?>">
          </div>
          <div>
            <label>Menit (0-59)</label>
            <input type="number" name="reminder_minute" min="0" max="59" value="<?= $remMin ?>">
          </div>
        </div>

        <div class="field-group">
          <label>Vibe Wallpaper</label>
          <select name="wallpaper_preset">
            <option value="bestie_candy" <?= $theme['wallpaper_preset'] === 'bestie_candy' ? 'selected' : '' ?>>Bestie Candy (Pink-Ungu-Biru)</option>
            <option value="mint_boba" <?= $theme['wallpaper_preset'] === 'mint_boba' ? 'selected' : '' ?>>Mint Boba Segar</option>
            <option value="sunny_peach" <?= $theme['wallpaper_preset'] === 'sunny_peach' ? 'selected' : '' ?>>Sunny Peach Ceria</option>
            <option value="lavender_pop" <?= $theme['wallpaper_preset'] === 'lavender_pop' ? 'selected' : '' ?>>Lavender Dot Pop</option>
            <option value="night_neon" <?= $theme['wallpaper_preset'] === 'night_neon' ? 'selected' : '' ?>>Night Neon Cozy</option>
            <option value="custom" <?= $theme['wallpaper_preset'] === 'custom' ? 'selected' : '' ?>>Gambar Sendiri (Upload di bawah)</option>
          </select>
        </div>

        <div class="field-group">
          <label>Upload Wallpaper dari HP (Opsional)</label>
          <input type="file" name="wallpaper_file" accept="image/*">
        </div>

        <div class="field-group">
          <label>Gaya Bubble Chat</label>
          <select name="bubble_style">
            <option value="gradient" <?= $bubbleStyle === 'gradient' ? 'selected' : '' ?>>Gradasi Lembut</option>
            <option value="pill" <?= $bubbleStyle === 'pill' ? 'selected' : '' ?>>Gelembung Bulat Penuh</option>
            <option value="classic" <?= $bubbleStyle === 'classic' ? 'selected' : '' ?>>Klasik Ujung Lancip</option>
            <option value="sharp" <?= $bubbleStyle === 'sharp' ? 'selected' : '' ?>>Garis Aksen Tegas</option>
          </select>
        </div>

        <div class="field-group">
          <label>Gaya Huruf</label>
          <select name="font_family">
            <option value="sans" <?= $theme['font_family'] === 'sans' ? 'selected' : '' ?>>Modern Bersih (Sans)</option>
            <option value="rounded" <?= $theme['font_family'] === 'rounded' ? 'selected' : '' ?>>Santai Membulat</option>
            <option value="serif" <?= $theme['font_family'] === 'serif' ? 'selected' : '' ?>>Elegan Klasik</option>
          </select>
        </div>

        <div class="field-group">
          <label>Ukuran Huruf: <span id="fontSizeVal"><?= (int) $theme['font_size'] ?>px</span></label>
          <input type="range" name="font_size" min="12" max="22" value="<?= (int) $theme['font_size'] ?>" oninput="document.getElementById('fontSizeVal').textContent = this.value + 'px'">
        </div>

        <div class="field-group color-row">
          <div>
            <label>Warna Font</label>
            <input type="color" name="text_color" value="<?= htmlspecialchars($theme['text_color'], ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div>
            <label>Bubble Kamu</label>
            <input type="color" name="user_bubble_bg" value="<?= htmlspecialchars($theme['user_bubble_bg'], ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div>
            <label>Bubble Bot</label>
            <input type="color" name="bot_bubble_bg" value="<?= htmlspecialchars($theme['bot_bubble_bg'], ENT_QUOTES, 'UTF-8') ?>">
          </div>
        </div>

        <div class="modal-actions">
          <button type="button" class="btn-cancel" onclick="closeModal()">Batal</button>
          <button type="submit" class="btn-save">Simpan</button>
        </div>
      </form>

      <div class="modal-actions" style="border-top:1px solid #e2e8f0; padding-top:10px;">
        <form method="POST" style="margin:0;">
          <input type="hidden" name="action" value="test_day_change">
          <button type="submit" class="btn-cancel">🌅 Ganti Hari</button>
        </form>
        <form method="POST" style="margin:0;" onsubmit="return confirm('Bersihkan riwayat chat saja? Saldo & transaksi tetap aman.');">
          <input type="hidden" name="action" value="reset_chat">
          <button type="submit" class="btn-soft-danger">Bersihkan Chat</button>
        </form>
      </div>
    </div>
  </div>

<script>
const NOTIF_ENABLED = <?= $notifEnabled ? 'true' : 'false' ?>;
const SOUND_ENABLED = <?= $soundEnabled ? 'true' : 'false' ?>;
const REM_HOUR = <?= $remHour ?>;
const REM_MIN = <?= $remMin ?>;
const BUDGET_TODAY = <?= json_encode(rupiah($budget)) ?>;
const REMAINING_TODAY = <?= json_encode(rupiah($remainingBudget)) ?>;
const HAS_NEW_NOTIF = <?= $notifPayload !== null ? 'true' : 'false' ?>;

const chatArea = document.getElementById('chatArea');
function scrollBottom() {
  chatArea.scrollTop = chatArea.scrollHeight;
}
scrollBottom();
window.addEventListener('load', scrollBottom);

function toggleAmounts() {
  document.getElementById('amountDrawer').classList.toggle('open');
}

function pickAmount(amtText) {
  const input = document.getElementById('msgInput');
  const cur = input.value.trim();
  if (!cur) {
    input.value = 'jajan ' + amtText;
  } else if (/\b\d+(?:[.,]\d+)?\s*(rb|ribu|k)\b/i.test(cur)) {
    input.value = cur.replace(/\b\d+(?:[.,]\d+)?\s*(rb|ribu|k)\b/i, amtText);
  } else {
    input.value = cur + ' ' + amtText;
  }
  document.getElementById('amountDrawer').classList.remove('open');
  input.focus();
}

function quickSend(text) {
  const input = document.getElementById('msgInput');
  input.value = text;
  document.getElementById('chatForm').submit();
}

function openRekap() {
  document.getElementById('rekapModal').classList.add('open');
}
function closeRekap() {
  document.getElementById('rekapModal').classList.remove('open');
}
function openModal() {
  document.getElementById('settingsModal').classList.add('open');
}
function closeModal() {
  document.getElementById('settingsModal').classList.remove('open');
}

function playRingtone() {
  if (!NOTIF_ENABLED || !SOUND_ENABLED) return;
  try {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtx) return;
    const ctx = new AudioCtx();
    if (ctx.state === 'suspended') {
      ctx.resume();
    }
    const notes = [
      { f: 987.77, t: 0.00, d: 0.12 },
      { f: 1318.51, t: 0.11, d: 0.14 },
      { f: 1567.98, t: 0.23, d: 0.24 }
    ];
    notes.forEach((n) => {
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'triangle';
      osc.frequency.setValueAtTime(n.f, ctx.currentTime + n.t);
      gain.gain.setValueAtTime(0.28, ctx.currentTime + n.t);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + n.t + n.d);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start(ctx.currentTime + n.t);
      osc.stop(ctx.currentTime + n.t + n.d);
    });
  } catch (e) {}
}

function pushTopNotif(title, body) {
  if (!NOTIF_ENABLED) return;
  playRingtone();

  const alertBox = document.getElementById('topAlert');
  alertBox.innerHTML = '<strong>🔔 ' + title + '</strong><br>' + body;
  alertBox.style.display = 'block';
  setTimeout(() => {
    alertBox.style.display = 'none';
  }, 5000);

  if ('Notification' in window) {
    if (Notification.permission === 'granted') {
      new Notification(title, { body: body });
    } else if (Notification.permission !== 'denied') {
      Notification.requestPermission().then((perm) => {
        if (perm === 'granted') {
          new Notification(title, { body: body });
        }
      });
    }
  }
}

document.addEventListener('pointerdown', () => {
  if (HAS_NEW_NOTIF) {
    playRingtone();
  }
}, { once: true });

<?php if ($notifPayload !== null): ?>
pushTopNotif(<?= json_encode($notifPayload['title']) ?>, <?= json_encode($notifPayload['body']) ?>);
<?php endif; ?>

let knownDateKey = new Date().toISOString().slice(0, 10);
setInterval(() => {
  if (!NOTIF_ENABLED) return;
  const now = new Date();
  const curDateKey = now.toISOString().slice(0, 10);

  if (curDateKey !== knownDateKey) {
    knownDateKey = curDateKey;
    pushTopNotif(
      'Tring! Pergantian Hari',
      'Budget harianmu ' + BUDGET_TODAY + ', sisa budgetmu hari ini ' + REMAINING_TODAY + ' ya.'
    );
    return;
  }

  if (now.getHours() === REM_HOUR && now.getMinutes() === REM_MIN && now.getSeconds() < 26) {
    pushTopNotif(
      'Tring! Sebentar Lagi Ganti Hari',
      'Budget harianmu ' + BUDGET_TODAY + ', sisa budgetmu hari ini ' + REMAINING_TODAY + ' ya.'
    );
  }
}, 20000);

let leaveAlertShown = false;
document.addEventListener('visibilitychange', () => {
  if (!NOTIF_ENABLED) return;
  if (document.hidden && !leaveAlertShown) {
    leaveAlertShown = true;
    if ('Notification' in window && Notification.permission === 'granted') {
      new Notification('Tring! Info Budget Harian', {
        body: 'Budget harianmu ' + BUDGET_TODAY + ', sisa budgetmu hari ini ' + REMAINING_TODAY + ' ya.'
      });
    }
  } else if (!document.hidden) {
    leaveAlertShown = false;
  }
});
</script>
</body>
</html>
