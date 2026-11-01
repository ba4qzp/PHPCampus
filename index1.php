<?php
require_once __DIR__ . '/src/init.php';
if(empty($_SESSION['uid'])){ header('Location: index.php?action=login'); exit; }
$uid = (int)$_SESSION['uid'];
$stmt = $pdo->prepare('SELECT * FROM users WHERE uid = ?'); $stmt->execute([$uid]); $urow = $stmt->fetch();
$with = isset($_GET['with']) ? (int)$_GET['with'] : 0;

// 获取总未读数
$stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE to_uid = ? AND is_read = 0 AND deleted = 0'); $stmt->execute([$uid]);
$unread = (int)$stmt->fetchColumn();

if($with){ $pdo->prepare('UPDATE messages SET is_read = 1 WHERE to_uid = ? AND from_uid = ?')->execute([$uid, $with]); }

// 获取最近有过联系的人（列表红点）
$contacts = [];
$stmt_c = $pdo->prepare("
    SELECT u.uid, u.username, u.nickname,
    (SELECT COUNT(*) FROM messages WHERE from_uid = u.uid AND to_uid = ? AND is_read = 0 AND deleted = 0) AS unread_count
    FROM users u
    WHERE u.uid IN (
        SELECT from_uid FROM messages WHERE to_uid = ? 
        UNION 
        SELECT to_uid FROM messages WHERE from_uid = ?
    ) AND u.uid != ?
    ORDER BY u.uid DESC LIMIT 10
");
$stmt_c->execute([$uid, $uid, $uid, $uid]);
$contacts = $stmt_c->fetchAll();

page_head('私聊'); ?>
<div class="nav"><b>私聊</b> <span class="badge-unread" id="navUnread" style="<?=$unread?'':'display:none'?>"><?=$unread?></span></div>
<div class="card">
    <form method="get"><input type="text" name="with" placeholder="输入对方 UID" value="<?=$with?:''?>"><button type="submit">找人</button></form>
    
    <!-- ★ 最近联系人列表（红点显示） -->
    <?php if($contacts): ?>
    <div style="margin-top:10px;border-top:1px dashed #eee;padding-top:10px;font-size:13px;">
        <div style="color:#888;margin-bottom:5px;">最近联系人：</div>
        <?php foreach($contacts as $c): ?>
            <a href="?with=<?=$c['uid']?>" style="display:inline-block;margin-right:10px;margin-bottom:5px;padding:4px 8px;background:#f3f4f6;border-radius:4px;text-decoration:none;color:#333;">
                <?=h(show_name($c))?>
                <?php if($c['unread_count'] > 0): ?>
                    <span style="background:red;color:white;border-radius:10px;padding:0 5px;font-size:11px;margin-left:4px;"><?=$c['unread_count']?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php 
if($with > 0) {
    $stmt = $pdo->prepare('SELECT uid, username, nickname FROM users WHERE uid = ?'); 
    $stmt->execute([$with]); 
    $withUser = $stmt->fetch();
    
    if($withUser) {
        $stmt1 = $pdo->prepare('SELECT * FROM messages WHERE from_uid = ? AND to_uid = ? ORDER BY mid DESC LIMIT 300');
        $stmt1->execute([$uid, $with]);
        $msg1 = $stmt1->fetchAll();

        $stmt2 = $pdo->prepare('SELECT * FROM messages WHERE from_uid = ? AND to_uid = ? ORDER BY mid DESC LIMIT 300');
        $stmt2->execute([$with, $uid]);
        $msg2 = $stmt2->fetchAll();

        $conv = array_merge($msg1, $msg2);
        usort($conv, function($a, $b) { return $a['mid'] - $b['mid']; }); 
        if (count($conv) > 300) { $conv = array_slice($conv, -300); }
?>
    <div class="card">
        <h3>与 <?=h(show_name($withUser))?> 的对话</h3>
        <div id="chatBody" style="max-height:60vh;overflow-y:auto;padding-right:4px">
            <?php foreach($conv as $m): $mine = ((int)$m['from_uid'] === $uid); ?>
                <div style="display:flex;<?=$mine?'justify-content:flex-end':''?>;margin-bottom:10px;" data-mid="<?=$m['mid']?>">
                    <div class="msg-bubble <?=$mine?'msg-me':'msg-other'?>" style="max-width:80%;">
                        <div style="font-size:10px;color:#888;margin-bottom:2px;"><?=date('m-d H:i', strtotime($m['created_at']))?></div>
                        
                        <?php if($m['deleted']): ?>
                            <div style="color:#999;font-style:italic">[该消息已撤回]</div>
                        <?php else: ?>
                            <?php if(!empty($m['reply_to'])): 
                                $rstmt = $pdo->prepare('SELECT content FROM messages WHERE mid = ?'); 
                                $rstmt->execute([$m['reply_to']]); 
                                $rcontent = $rstmt->fetchColumn();
                                $rcontent = $rcontent ? mb_substr(str_replace(["\r", "\n"], ' ', $rcontent), 0, 20) . '...' : '[已撤回]';
                            ?>
                                <div style="background:rgba(0,0,0,0.05);border-left:2px solid #ccc;padding:4px;font-size:12px;margin-bottom:4px;color:#666;">
                                    引用：<?=h($rcontent)?>
                                </div>
                            <?php endif; ?>

                            <div class="md"><?=nl2br(h($m['content']))?></div>
                            
                            <?php if($m['file_path']): ?>
                                <?php if(is_image($m['file_path'])): ?>
                                    <img src="<?=h($m['file_path'])?>" style="max-width:100%;border-radius:4px;margin-top:4px;">
                                <?php else: ?>
                                    <a href="<?=h($m['file_path'])?>" target="_blank">📎 附件: <?=h($m['file_name'])?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <div style="font-size:11px;margin-top:4px;text-align:right;">
                                <a href="javascript:void(0)" onclick="quoteMsg(<?=$m['mid']?>, this)" style="color:#2563eb;">引用</a>
                                <?php if($mine && time() - strtotime($m['created_at']) < 120): ?>
                                    | <a href="javascript:void(0)" onclick="recallMsg(<?=$m['mid']?>)" style="color:#dc2626;">撤回</a>
                                <?php endif; ?>
                                | <a href="javascript:void(0)" onclick="forwardMsg(<?=$m['mid']?>)" style="color:#2563eb;">转发</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <div id="chatEnd"></div>
        </div>
        
        <form id="msgForm" enctype="multipart/form-data" style="margin-top:10px">
            <input type="hidden" name="to" value="<?=$with?>">
            <input type="hidden" name="reply_to" id="replyToInput" value="">
            <div id="quotePreview" style="display:none;background:#f3f4f6;padding:5px;font-size:12px;margin-bottom:5px;border-radius:4px;">
                正在引用: <span id="quoteText"></span> <a href="javascript:void(0)" onclick="cancelQuote()">取消</a>
            </div>
            <textarea name="content" placeholder="说点什么..." style="width:100%;min-height:60px;"></textarea>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:5px;">
                <input type="file" name="file" style="font-size:12px;">
                <button type="submit" id="sendBtn">发送</button>
            </div>
        </form>
    </div>
    
    <script>
    var WITH_ID = <?=$with?>;
    var chatBody = document.getElementById('chatBody');
    var chatEnd  = document.getElementById('chatEnd');
    var lastMid  = <?=count($conv) ? (int)end($conv)['mid'] : 0?>;
    var sendBtn  = document.getElementById('sendBtn');

    function scrollBottom(){ chatEnd.scrollIntoView({block:'end'}); } 
    window.addEventListener('load', scrollBottom);
    
    function quoteMsg(mid, el) {
        var mdDiv = el.closest('.msg-bubble').querySelector('.md');
        var content = mdDiv ? mdDiv.innerText.replace(/\n/g, ' ') : '';
        document.getElementById('replyToInput').value = mid;
        document.getElementById('quoteText').innerText = content.substring(0, 20) + '...';
        document.getElementById('quotePreview').style.display = 'block';
    }
    
    function cancelQuote() {
        document.getElementById('replyToInput').value = '';
        document.getElementById('quotePreview').style.display = 'none';
    }
    
    function recallMsg(mid) {
        if(!confirm('确定撤回该消息？')) return;
        fetch('send_msg.php?action=recall', {method:'POST', body: new URLSearchParams({mid: mid})})
            .then(r => r.json()).then(j => {
                if(j.ok) location.reload(); else alert('撤回失败');
            });
    }
    
    function forwardMsg(mid) {
        var to = prompt("输入要转发给对方的 UID:");
        if(!to) return;
        fetch('send_msg.php?action=forward', {method:'POST', body: new URLSearchParams({mid: mid, to: to})})
            .then(r => r.json()).then(j => {
                if(j.ok) alert('转发成功'); else alert('转发失败');
            });
    }

    function addBubble(role, content, imagePath, isFirst){
        var wrap = document.createElement('div');
        wrap.style.display = 'flex';
        wrap.style.marginBottom = '10px';
        if(role === 'me'){ wrap.style.justifyContent = 'flex-end'; }
        var bubble = document.createElement('div');
        bubble.className = 'msg-bubble ' + (role === 'me' ? 'msg-me' : 'msg-other');
        bubble.style.maxWidth = '80%';
        
        var timeDiv = document.createElement('div');
        timeDiv.style.cssText = 'font-size:10px;color:#888;margin-bottom:2px;';
        var now = new Date();
        timeDiv.innerText = (now.getMonth()+1) + '-' + now.getDate() + ' ' + now.getHours() + ':' + String(now.getMinutes()).padStart(2,'0');
        bubble.appendChild(timeDiv);

        if(imagePath){
            var img = document.createElement('img');
            img.src = imagePath; img.style.maxWidth = '100%'; img.style.borderRadius = '4px';
            bubble.appendChild(img);
        } else {
            var md = document.createElement('div');
            md.className = 'md';
            md.innerHTML = content.replace(/\n/g, '<br>');
            bubble.appendChild(md);
        }
        wrap.appendChild(bubble);
        chatBody.insertBefore(wrap, chatEnd);
        chatEnd.scrollIntoView({block:'end'});
    }

    document.getElementById('msgForm').onsubmit = async function(e){
        e.preventDefault();
        var txt = this.querySelector('textarea').value.trim();
        if(!txt) { alert('不能发送空消息'); return; }
        sendBtn.disabled = true;
        var fd = new FormData(this);
        try {
            var r = await fetch('send_msg.php', {method:'POST', body:fd});
            var text = await r.text();
            var j;
            try { j = JSON.parse(text); } catch(e) { alert('服务器返回非JSON：\n' + text.substring(0,300)); sendBtn.disabled = false; return; }
            if(j.ok){
                addBubble('me', txt, null);
                this.querySelector('textarea').value = '';
                this.querySelector('input[type=file]').value = '';
                cancelQuote();
                if(j.mid) lastMid = j.mid;
            } else {
                alert('发送失败：' + (j.msg || '未知'));
            }
        } catch(err) {
            alert('网络错误：' + err.message);
        }
        sendBtn.disabled = false;
    };

    // 轮询新消息（仅追加，不刷新页面）
    setInterval(async function(){
        try {
            var r = await fetch('pull.php?with=' + WITH_ID + '&last=' + lastMid);
            var j = await r.json();
            if(j.messages && j.messages.length){
                j.messages.forEach(function(m){
                    addBubble('other', m.content, m.file_path);
                    if(m.mid > lastMid) lastMid = m.mid;
                });
                // 更新顶部红点
                var navBadge = document.getElementById('navUnread');
                if(navBadge){
                    navBadge.innerText = parseInt(navBadge.innerText || 0) + j.messages.length;
                    navBadge.style.display = 'inline-block';
                }
            }
        } catch(e) {}
    }, 3000);
    </script>
<?php 
    } else {
        echo '<div class="card"><p style="color:red">找不到该用户</p></div>';
    }
}
page_end(); 
?>
