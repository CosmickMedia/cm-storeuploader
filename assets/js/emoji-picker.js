window.initEmojiPicker = function(textarea, button, pickerEl){
    const emojis = ['😀','😁','😂','🤣','😊','😎','😍','😘','😜','🤔','👍','👎','🎉','❤️','🔥','✨'];
    pickerEl.innerHTML = '';
    emojis.forEach(em=>{
        const span=document.createElement('span');
        span.textContent=em;
        span.className='emoji-option';
        span.addEventListener('click',()=>{
            textarea.value += em;
            pickerEl.style.display='none';
            textarea.focus();
        });
        pickerEl.appendChild(span);
    });
    button.addEventListener('click',()=>{
        pickerEl.style.display = pickerEl.style.display==='none' ? 'block' : 'none';
    });
};
