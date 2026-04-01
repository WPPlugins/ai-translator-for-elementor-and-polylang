/**
 * Settings Page JavaScript
 * Handles API testing and cache clearing functionality
 */

function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    if (field.type === 'password') {
        field.type = 'text';
    } else {
        field.type = 'password';
    }
}

function testApiConnection(provider) {
    if (provider !== 'openai') return; // Only support OpenAI now
    
    const button = document.getElementById('test-openai-api');
    const statusDiv = document.getElementById('openai-api-status');
    const apiKey = document.getElementById('aitrfoel_openai_api_key').value;
    
    if (!apiKey.trim()) {
        showApiStatus(statusDiv, 'error', '❌ Please enter an API key first');
        return;
    }
    
    // Disable button and show loading
    button.disabled = true;
    button.innerHTML = '⏳ Testing...';
    showApiStatus(statusDiv, 'loading', '🔄 Testing API connection...');
    
    // Make AJAX request
    const formData = new FormData();
    formData.append('action', 'aitrfoel_test_api');
    formData.append('provider', 'openai');
    formData.append('api_key', apiKey);
    formData.append('nonce', aitrfoel_settings_ajax.test_nonce);
    
    fetch(aitrfoel_settings_ajax.ajax_url, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        button.disabled = false;
        button.innerHTML = '🔍 Test';
        
        if (data.success) {
            showApiStatus(statusDiv, 'success', '✅ ' + data.data);
        } else {
            showApiStatus(statusDiv, 'error', '❌ ' + data.data);
        }
    })
    .catch(error => {
        button.disabled = false;
        button.innerHTML = '🔍 Test';
        showApiStatus(statusDiv, 'error', '❌ Network error: ' + error.message);
    });
}

function showApiStatus(statusDiv, type, message) {
    statusDiv.innerHTML = message;
    statusDiv.className = 'api-status api-status-' + type;
    
    // Auto-hide after 10 seconds for success messages
    if (type === 'success') {
        setTimeout(() => {
            statusDiv.innerHTML = '';
            statusDiv.className = 'api-status';
        }, 10000);
    }
}

// Clear cache button handler
document.addEventListener('DOMContentLoaded', function() {
    const clearCacheBtn = document.getElementById('aitrfoel-clear-cache-btn');
    const statusSpan = document.getElementById('aitrfoel-clear-cache-status');
    
    if (clearCacheBtn) {
        clearCacheBtn.addEventListener('click', function() {
            if (!confirm(aitrfoel_settings_ajax.clear_cache_confirm)) {
                return;
            }
            
            // Disable button and show loading
            clearCacheBtn.disabled = true;
            clearCacheBtn.innerHTML = '⏳ ' + aitrfoel_settings_ajax.clearing_text;
            statusSpan.innerHTML = '';
            
            // Make AJAX request
            const formData = new FormData();
            formData.append('action', 'aitrfoel_clear_cache');
            formData.append('nonce', aitrfoel_settings_ajax.clear_cache_nonce);
            
            fetch(aitrfoel_settings_ajax.ajax_url, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                clearCacheBtn.disabled = false;
                clearCacheBtn.innerHTML = '🗑️ ' + aitrfoel_settings_ajax.clear_cache_text;
                
                if (data.success) {
                    statusSpan.innerHTML = '<span style="color: green;">✅ ' + data.data.message + '</span>';
                    setTimeout(() => {
                        statusSpan.innerHTML = '';
                    }, 5000);
                } else {
                    statusSpan.innerHTML = '<span style="color: red;">❌ ' + data.data + '</span>';
                }
            })
            .catch(error => {
                clearCacheBtn.disabled = false;
                clearCacheBtn.innerHTML = '🗑️ ' + aitrfoel_settings_ajax.clear_cache_text;
                statusSpan.innerHTML = '<span style="color: red;">❌ Error: ' + error.message + '</span>';
            });
        });
    }
});
