<?php
require_once __DIR__ . '/src/init.php';
if(!HAS_AI){ die('本站未启用 AI 功能。'); }
if(empty($_SESSION['uid'])){ header('Location: index.php?action=login'); exit; }

$uid = (int)$_SESSION['uid'];
$stmt = $pdo->prepare('SELECT * FROM users WHERE uid = ?'); $stmt->execute([$uid]); $urow = $stmt->fetch();

$conv_id = (int)($_GET['c'] ?? 0);
if(!$conv_id){
    $stmt = $pdo->prepare('SELECT id FROM ai_convs WHERE uid = ? ORDER BY updated_at DESC LIMIT 1'); $stmt->execute([$uid]);
    $conv_id = (int)$stmt->fetchColumn();
    if(!$conv_id){
        $pdo->prepare('INSERT INTO ai_convs (uid) VALUES (?)')->execute([$uid]);
        $conv_id = (int)$pdo->lastInsertId();
    }
    header('Location: ai.php?c='.$conv_id); exit;
}

$stmt = $pdo->prepare('SELECT * FROM ai_msgs WHERE conv_id = ? ORDER BY id ASC LIMIT 200'); $stmt->execute([$conv_id]); $msgs = $stmt->fetchAll();
page_head('AI 助手'); ?>
<div class="nav">
    <a href="ai.php?action=new" onclick="event.preventDefault();newConv()">＋ 新对话</a>
</div>
<div class="card" id="chatBody" style="min-height:300px;max-height:60vh;overflow-y:auto">
    <?php foreach($msgs as $m): ?>
        <div style="display:flex;<?=$m['role']=='user'?'justify-content:flex-end':''?>">
            <div class="msg-bubble <?=$m['role']=='user'?'msg-me':'msg-other'?>">
                <div class="md"><?=h($m['content'])?></div>
                <?php if($m['image_path']): ?><img src="<?=h($m['image_path'])?>" style="max-width:150px;border-radius:4px;margin-top:4px"><?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<div class="card">
    <form id="aiForm">
        <textarea id="txt" placeholder="说点什么... (支持 /画 描述)"></textarea>
        <button type="submit" id="sendBtn">发送</button>
    </form>
</div>
<script>
var CONV_ID = <?=$conv_id?>;
var chatBody = document.getElementById('chatBody');
var sendBtn  = document.getElementById('sendBtn');
var txtEl    = document.getElementById('txt');

function makeBubble(role, content, imagePath){
    var wrap = document.createElement('div');
    wrap.style.display = 'flex';
    if(role === 'user'){ wrap.style.justifyContent = 'flex-end'; }

    var bubble = document.createElement('div');
    bubble.className = 'msg-bubble ' + (role === 'user' ? 'msg-me' : 'msg-other');

    if(imagePath){
        var img = document.createElement('img');
        img.src = imagePath;
        img.style.maxWidth = (role === 'user' ? '150px' : '200px');
        img.style.borderRadius = '4px';
        bubble.appendChild(img);
    } else {
        var md = document.createElement('div');
        md.className = 'md';
        md.textContent = content;
        bubble.appendChild(md);
    }

    wrap.appendChild(bubble);
    return wrap;
}

function scrollBottom(){ chatBody.scrollTop = chatBody.scrollHeight; }

function newConv(){
    fetch('ai_api.php?action=new')
        .then(function(r){ return r.json(); })
        .then(function(j){ location.href = 'ai.php?c=' + j.id; });
}

document.getElementById('aiForm').onsubmit = async function(e){
    e.preventDefault();
    var txt = txtEl.value.trim();
    if(!txt) return;

    chatBody.appendChild(makeBubble('user', txt, null));
    scrollBottom();
    txtEl.value = '';
    sendBtn.disabled = true;

    try {
        var r = await fetch('ai_api.php?action=chat', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({conv_id: CONV_ID, text: txt})
        });
        var j = await r.json();
        if(j.ok){
            chatBody.appendChild(makeBubble('assistant', j.msg.content, j.msg.image_path));
            if(window.mdRenderAll) window.mdRenderAll();
            scrollBottom();
        } else {
            alert(j.msg || '发送失败');
        }
    } catch(err) {
        alert('网络错误：' + err.message);
    } finally {
        sendBtn.disabled = false;
    }
};
</script>
<?php page_end(); ?>
