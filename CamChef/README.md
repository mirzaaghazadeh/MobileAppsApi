# OpenAI Image Chat PHP Script

A pure PHP script that integrates with OpenAI's ChatGPT API to analyze images using vision capabilities.

## Features

- Upload images through a web interface
- Temporary file handling with automatic cleanup
- Secure API key management via .env file
- Support for multiple image formats (JPEG, PNG, GIF, WebP)
- Custom prompt ID integration
- Error handling and user-friendly responses

## Setup

1. **Configure API Key**
   - Open the `.env` file
   - Replace `your_openai_api_key_here` with your actual OpenAI API key:
     ```
     OPENAI_API_KEY=sk-your-actual-api-key-here
     ```

2. **Server Requirements**
   - PHP 7.0 or higher
   - cURL extension enabled
   - File upload permissions

3. **Directory Permissions**
   - Ensure the script can create the `temp_uploads/` directory
   - Set appropriate write permissions for temporary file storage

## Usage

1. **Web Interface**
   - Access the script through your web browser
   - Upload an image using the form
   - View the AI analysis response

2. **API Usage**
   - Send POST requests with image files to the script
   - Receive JSON responses with AI analysis

## API Response Format

```json
{
  "success": true,
  "response": {
    "choices": [
      {
        "message": {
          "content": "AI analysis of the image..."
        }
      }
    ]
  },
  "message": "Image processed successfully"
}
```

## Security Features

- API key stored securely in .env file
- Temporary files are automatically deleted after processing
- File type validation for uploaded images
- Error handling to prevent information disclosure

## Prompt ID

The script uses the specified prompt ID: `pmpt_6898f03dbdbc8197a54a35fcc707a91f01e7adb5cb7bd1e3`

This can be modified in the script if needed.

## Troubleshooting

- Ensure your OpenAI API key is valid and has sufficient credits
- Check that the `temp_uploads/` directory is writable
- Verify that cURL is enabled in your PHP installation
- Make sure file uploads are enabled in your PHP configuration