<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<html>
  <head>
    <title>Easyresizer test page</title>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  </head>
  <body>
      <h2>Original image(resized to 20% by browser):</h2>
      <pre><?php echo htmlspecialchars('<img src="img/image.jpg" style="width:20%" />') ?></pre>
      <img src="img/image.jpg" style="width:20%" />
      <br/>

      <h2>Restricted by 200px in width:</h2>
      <pre><?php echo htmlspecialchars('<img src="img/200_0/image.jpg" />') ?></pre>
      <img src="img/200_0/image.jpg" />
      <br/>

      <h2>Restricted by 400px in height:</h2>
      <pre><?php echo htmlspecialchars('<img src="img/0_400/image.jpg" />') ?></pre>
      <img src="img/0_400/image.jpg" />
      <br/>

      <h2>Image must fit 250x250 pixels square add be cropped from end of original:</h2>
      <pre><?php echo htmlspecialchars('<img src="img/250_250_e/image.jpg" />') ?></pre>
      <img src="img/250_250_e/image.jpg" />
  </body>
</html>
